<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function sprintf;

/**
 * "Only one default environment per workspace" under contention (audit ZH-L1, 1.20). Inside the caller's transaction,
 * before the environment's own row is claimed: the workspace row is locked (`SELECT … FOR UPDATE`, so two saves that
 * set a default run one after the other, parent before child like every edit), then one statement clears the flag of
 * the workspace's other environments and moves their row versions. The second save reads the first one's commit (a
 * current read on MySQL / MariaDB, a new statement snapshot on PostgreSQL READ COMMITTED) and clears it: one default.
 * Stateless (worker-safe).
 */
final class DefaultEnvironmentSwitch
{
    public static function makeDefault(EntityManagerInterface $manager, ApiEnvironment $environment): void
    {
        $workspace = $environment->getWorkspace();
        if (!$workspace instanceof ApiWorkspace || $workspace->getId() === null || $environment->getId() === null) {
            throw new LogicException('Api Studio: the default environment needs a stored environment and workspace.');
        }

        $manager->lock($workspace, LockMode::PESSIMISTIC_WRITE);
        $manager->createQuery(sprintf(
            'UPDATE %s e SET e.isDefault = false, e.rowVersion = e.rowVersion + 1 WHERE e.workspace = :workspace AND e.id <> :id AND e.isDefault = true',
            ApiEnvironment::class,
        ))
            ->setParameter('workspace', $workspace->getId())
            ->setParameter('id', $environment->getId())
            ->execute();
    }
}
