<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;

use function array_key_exists;

/**
 * Keeps `row_version` moving for every ORM write of a versioned row (1.20, REQ-RUNTIME-003 rule 4), so an edit form
 * rendered before any other writer (seed API, documentation, folder moves, environment variables, the default flag of
 * the other environments) is refused instead of overwriting that change:
 *
 * - an updated workspace / service / endpoint / environment whose changes do not include `rowVersion` (an edit that
 *   claimed the row already moved it, {@see RowVersionGuard::claim()});
 * - the parent endpoint of a written translation and the parent environment of a written variable (the edit forms
 *   include them), unless that parent is new, claimed or being deleted in the same flush.
 *
 * The statement is an atomic `row_version = row_version + 1` inside the flush's transaction (post* events run there);
 * a flush that writes a row and its children moves the row's version once per written row (only growth matters).
 * Stateless (worker-safe); does nothing for other entities or other managers' entities.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postRemove)]
final class RowVersionListener
{
    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if ($entity instanceof RowVersionedInterface) {
            $this->bumpUnlessTracked($event->getObjectManager(), $entity);

            return;
        }

        $this->bumpParent($event->getObjectManager(), $entity);
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->bumpParent($event->getObjectManager(), $event->getObject());
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $this->bumpParent($event->getObjectManager(), $event->getObject());
    }

    private function bumpParent(EntityManagerInterface $manager, object $entity): void
    {
        $parent = match (true) {
            $entity instanceof ApiEndpointTranslation => $entity->getEndpoint(),
            $entity instanceof ApiEnvironmentVariable => $entity->getEnvironment(),
            default                                   => null,
        };
        if (!$parent instanceof RowVersionedInterface || $parent->getId() === null) {
            return;
        }

        $this->bumpUnlessTracked($manager, $parent);
    }

    /**
     * Bumps the row unless this flush already sets its version (a new row, a claimed edit) or deletes it.
     */
    private function bumpUnlessTracked(EntityManagerInterface $manager, RowVersionedInterface $row): void
    {
        $uow = $manager->getUnitOfWork();
        if (!$uow->isInIdentityMap($row) || $uow->isScheduledForDelete($row) || array_key_exists('rowVersion', $uow->getEntityChangeSet($row))) {
            return;
        }

        RowVersionGuard::bump($manager, $row);
    }
}
