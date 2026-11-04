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

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $this->secretVariable($args);
        if (!$entity instanceof ApiEnvironmentVariable) {
            return;
        }

        // @igor-ignore - Not shared worker service state.
        $entity->setValue($this->cipher->encrypt($entity->getValue()));
    }

    /**
     * Covers variables marked as secret after persist() or without a value change: `value` is then
     * either already computed as plaintext or missing from the change set, and
     * {@see PreUpdateEventArgs::setNewValue()} only accepts fields already in it.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $em       = $args->getObjectManager();
        $uow      = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ApiEnvironmentVariable::class);
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret() || $entity->isUnreadable()) {
                continue;
            }

            // Already a payload of this key (prefix alone is not enough: user input may start with it).
            if ($this->cipher->isEncrypted($entity->getValue()) && $this->cipher->tryDecrypt($entity->getValue()) !== null) {
                continue;
            }

            if ($uow->isScheduledForUpdate($entity) && isset($uow->getEntityChangeSet($entity)['value'])) {
                continue;
            }

            $entity->setValue($this->cipher->encrypt($entity->getValue()));
            $uow->recomputeSingleEntityChangeSet($metadata, $entity);
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $this->secretVariable($args);
        if (!$entity instanceof ApiEnvironmentVariable || $entity->isUnreadable() || !$args->hasChangedField('value')) {
            return;
        }

        $newValue = $args->getNewValue('value');
        if (!is_string($newValue)) {
            return;
        }

        $args->setNewValue('value', $this->cipher->encrypt($newValue));
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->decryptIfNeeded($args);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->decryptIfNeeded($args);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $this->decryptIfNeeded($args);
    }

    /**
     * Decrypts the loaded payload of a secret variable — also with encryption turned off, so a payload written while it
     * was on is never handed out as a value. A payload the key cannot open marks the variable unreadable (fail closed).
     *
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    private function decryptIfNeeded(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret() || $entity->isUnreadable()) {
            return;
        }

        $stored = $entity->getStoredValue();
        if (!$this->cipher->isEncrypted($stored)) {
            return;
        }

        $plain = $this->cipher->tryDecrypt($stored);
        // Releases before 1.10.1 encrypted an unreadable payload a second time on save: its plaintext is itself a
        // payload. Unwrap it when the key opens it, else it is unreadable too (never sent as a value).
        if ($plain !== null && $this->cipher->isWrappedPayload($plain)) {
            $plain = $this->cipher->tryDecrypt($plain);
        }
        if ($plain !== null) {
            // @igor-ignore - Not shared worker service state.
            $entity->setValue($plain);

            return;
        }

        $entity->markUnreadable();
        $this->logger?->warning('Api Studio secret variable cannot be decrypted with the configured key: it needs re-entry and is never sent.', [
            'bundle'         => 'api-studio',
            'action'         => 'secret_unreadable',
            'variable_id'    => $entity->getId(),
            'environment_id' => $entity->getEnvironment()?->getId(),
        ]);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    private function secretVariable(LifecycleEventArgs $args): ?ApiEnvironmentVariable
    {
        if (!$this->enabled) {
            return null;
        }

        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret()) {
            return null;
        }

        return $entity;
    }
}
