<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use Psr\Log\LoggerInterface;

use function is_string;

/**
 * Encrypts secret environment variable values before flush; decrypts after load and after flush.
 *
 * Managed entities always hold the plaintext value in memory; only the database row holds ciphertext.
 *
 * Fail closed: a stored payload that the configured key cannot open (another key, corrupt row) marks the variable
 * unreadable ({@see ApiEnvironmentVariable::isUnreadable()}). Its payload is never encrypted again, never handed out
 * (not sent, not exported) and stays byte-identical in the row until someone re-enters the value.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
final class SecretVariableEncryptionListener
{
    public function __construct(
        private readonly SecretValueCipher $cipher,
        private readonly bool $enabled,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A new secret is always encrypted from its value as typed (1.11): stored payloads only ever come from the cipher.
     * With encryption off, only a value that looks like a payload is encrypted (so it reads back as text).
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$this->needsEncryption($entity, $entity->getValue())) {
            return;
        }

        $entity->storeCiphertext($this->cipher->encryptValue($entity->getValue()));
    }

    /**
     * Covers variables marked as secret after persist() or without a value change: `value` is then
     * either already computed as plaintext or missing from the change set, and
     * {@see PreUpdateEventArgs::setNewValue()} only accepts fields already in it.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em       = $args->getObjectManager();
        $uow      = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ApiEnvironmentVariable::class);
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof ApiEnvironmentVariable || !$this->needsEncryption($entity, $entity->getValue())) {
                continue;
            }

            if ($uow->isScheduledForUpdate($entity) && isset($uow->getEntityChangeSet($entity)['value'])) {
                continue;
            }

            $entity->storeCiphertext($this->cipher->encryptValue($entity->getValue()));
            $uow->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$args->hasChangedField('value')) {
            return;
        }

        $newValue = $args->getNewValue('value');
        if (!is_string($newValue) || !$this->needsEncryption($entity, $newValue)) {
            return;
        }

        // The entity keeps its plaintext; only the written column is the payload.
        $args->setNewValue('value', $this->cipher->encryptValue($newValue));
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->restoreAfterFlush($args);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->restoreAfterFlush($args);
    }

    /**
     * Decrypts the loaded payload of a secret variable — also with encryption turned off, so a payload written while it
     * was on is never handed out as a value. A payload the key cannot open marks the variable unreadable (fail closed).
     *
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret() || $entity->isUnreadable()) {
            return;
        }

        $stored = $entity->getStoredValue();
        if (!$this->cipher->isEncrypted($stored)) {
            return;
        }

        $plain = $this->cipher->openStored($stored);
        if ($plain !== null) {
            $entity->restorePlaintext($plain);

            return;
        }

        $this->markUnreadable($entity);
    }

    /**
     * A secret whose value is a value as typed (not the payload written in this flush, not an unreadable payload):
     * encrypted when encryption is on, or when it looks like a payload.
     */
    private function needsEncryption(ApiEnvironmentVariable $entity, string $value): bool
    {
        if (!$entity->isSecret() || $entity->isUnreadable() || $entity->holdsCiphertext()) {
            return false;
        }

        return $this->enabled || $this->cipher->isEncrypted($value);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    private function restoreAfterFlush(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->holdsCiphertext()) {
            return;
        }

        $plain = $this->cipher->openStored($entity->getStoredValue());
        if ($plain !== null) {
            $entity->restorePlaintext($plain);

            return;
        }

        $this->markUnreadable($entity);
    }

    private function markUnreadable(ApiEnvironmentVariable $entity): void
    {
        $entity->markUnreadable();
        $this->logger?->warning('Api Studio secret variable cannot be decrypted with the configured key: it needs re-entry and is never sent.', [
            'bundle'         => 'api-studio',
            'action'         => 'secret_unreadable',
            'variable_id'    => $entity->getId(),
            'environment_id' => $entity->getEnvironment()?->getId(),
        ]);
    }
}
