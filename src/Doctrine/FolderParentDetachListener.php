<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

/**
 * 1.14 folders: `folder.parent_id` has no ON DELETE action (audit RJ-M2), so the database never removes a folder with
 * its subtree. When a flush deletes services or workspaces, their folders go with them by the `service_id` cascade;
 * this listener first unsets the parent of those folders **as part of the same flush** (entity change sets, audit
 * RJ-D-M1): the UPDATEs run inside the flush transaction, before the deletes, and roll back with them if the delete
 * fails. No SQL is run from the event itself (onFlush is dispatched before the flush opens its transaction).
 *
 * Stateless (worker-safe); does nothing while folders are off.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FolderParentDetachListener
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $em  = $event->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Only a flush that deletes Api Studio services / workspaces concerns folders (other managers never get here).
        $services   = [];
        $workspaces = [];
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof ApiService && $entity->getId() !== null) {
                $services[] = $entity->getId();
            } elseif ($entity instanceof ApiWorkspace && $entity->getId() !== null) {
                $workspaces[] = $entity->getId();
            }
        }
        if ($services === [] && $workspaces === []) {
            return;
        }
        $metadata = $em->getClassMetadata(ApiFolder::class);
        if ($metadata->isMappedSuperclass) {
            return;
        }

        $query = $em->createQueryBuilder()
            ->select('f')->from(ApiFolder::class, 'f')->join('f.service', 's')
            ->where('f.parent IS NOT NULL');
        $or = [];
        if ($services !== []) {
            $or[] = 's.id IN (:services)';
            $query->setParameter('services', $services);
        }
        if ($workspaces !== []) {
            $or[] = 'IDENTITY(s.workspace) IN (:workspaces)';
            $query->setParameter('workspaces', $workspaces);
        }
        $query->andWhere(implode(' OR ', $or));

        /** @var list<ApiFolder> $folders */
        $folders = $query->getQuery()->getResult();
        foreach ($folders as $folder) {
            $folder->setParent(null);
            $uow->recomputeSingleEntityChangeSet($metadata, $folder);
        }
    }
}
