<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function sprintf;

/**
 * 1.14 folders: `folder.parent_id` has no ON DELETE action (audit RJ-M2), so the database never removes a folder with
 * its subtree. When a flush deletes services or workspaces, their folders are cascaded by `service_id`; this listener
 * first detaches the parent links of those folders (in the same transaction, before the deletes run) so the cascade
 * is not refused by the self-reference. Stateless (worker-safe); does nothing while folders are off.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FolderParentDetachListener
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $em = $event->getObjectManager();

        // Only a flush that deletes Api Studio services / workspaces concerns folders (other managers never get here).
        $services   = [];
        $workspaces = [];
        foreach ($em->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
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

        $folderTable  = $metadata->getTableName();
        $serviceTable = $em->getClassMetadata(ApiService::class)->getTableName();
        $connection   = $em->getConnection();
        if ($services !== []) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET parent_id = NULL WHERE service_id IN (?)', $folderTable),
                [$services],
                [ArrayParameterType::INTEGER],
            );
        }
        if ($workspaces !== []) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET parent_id = NULL WHERE service_id IN (SELECT id FROM %s WHERE workspace_id IN (?))', $folderTable, $serviceTable),
                [$workspaces],
                [ArrayParameterType::INTEGER],
            );
        }
    }
}
