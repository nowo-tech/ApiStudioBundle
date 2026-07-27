<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;

/**
 * Encrypts secret environment variable values before flush; decrypts after load.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
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
        $this->encryptIfNeeded($args);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->encryptIfNeeded($args);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret()) {
            return;
        }

        $entity->setValue($this->cipher->decrypt($entity->getValue()));
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    private function encryptIfNeeded(LifecycleEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $entity = $args->getObject();
        if (!$entity instanceof ApiEnvironmentVariable || !$entity->isSecret()) {
            return;
        }

        $entity->setValue($this->cipher->encrypt($entity->getValue()));
    }
}
