<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function array_keys;
use function implode;

/**
 * 1.14 folders: `folder.parent_id` has no ON DELETE action (audit RJ-M2), so the database never removes a folder with
 * its subtree on its own. When a flush deletes services or workspaces, this listener schedules **every folder** of
 * those services for deletion in the same flush (audits RJ-D-M1, RJ-D2-M1):
 *
 * - the deletes run inside the flush transaction (no SQL from the event, which is dispatched before the flush opens
 *   its transaction) and roll back with a failed delete;
 * - the ORM orders them (subfolders before their parent, folders before their service) and removes them from the
 *   identity map, so no managed folder is left pointing at a deleted service and later flushes on the same manager
 *   keep working (the host's default manager is never cleared).
 *
 * The folders are read with every SQL filter suspended (RJ-D2-L2): a tenant or host filter must not hide a folder
 * that the database would then refuse to orphan. Stateless (worker-safe); does nothing while folders are off.
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
        if ($em->getClassMetadata(ApiFolder::class)->isMappedSuperclass) {
            return;
        }

        $query = $em->createQueryBuilder()->select('f')->from(ApiFolder::class, 'f')->join('f.service', 's');
        $or    = [];
        if ($services !== []) {
            $or[] = 's.id IN (:services)';
            $query->setParameter('services', $services);
        }
        if ($workspaces !== []) {
            $or[] = 'IDENTITY(s.workspace) IN (:workspaces)';
            $query->setParameter('workspaces', $workspaces);
        }
        $query->where(implode(' OR ', $or));

        $filters   = $em->getFilters();
        $suspended = array_keys($filters->getEnabledFilters());
        foreach ($suspended as $name) {
            $filters->suspend($name);
        }
        try {
            /** @var list<ApiFolder> $folders */
            $folders = $query->getQuery()->getResult();
        } finally {
            foreach ($suspended as $name) {
                $filters->restore($name);
            }
        }

        foreach ($folders as $folder) {
            if (!$uow->isScheduledForDelete($folder)) {
                $uow->scheduleForDelete($folder);
            }
        }
    }
}
