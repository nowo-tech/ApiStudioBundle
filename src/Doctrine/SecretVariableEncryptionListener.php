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

use function is_string;

/**
 * Encrypts secret environment variable values before flush; decrypts after load and after flush.
 *
 * Managed entities always hold the plaintext value in memory; only the database row holds ciphertext.
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
            if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret()) {
                continue;
            }

            if ($this->cipher->isEncrypted($entity->getValue())) {
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
        if (!$entity instanceof ApiEnvironmentVariable || !$args->hasChangedField('value')) {
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
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    private function decryptIfNeeded(LifecycleEventArgs $args): void
    {
        $entity = $this->secretVariable($args);
        if (!$entity instanceof ApiEnvironmentVariable) {
            return;
        }

        // @igor-ignore - Not shared worker service state.
        $entity->setValue($this->cipher->decrypt($entity->getValue()));
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
