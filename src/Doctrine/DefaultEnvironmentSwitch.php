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
 * "Only one default environment per workspace" under contention (audit ZH-L1, 1.20; the create page since 1.21.1).
 * Inside the caller's transaction, before the environment's own row is written: the workspace row is locked
 * (`SELECT … FOR UPDATE`, so two saves that set a default run one after the other, parent before child like every
 * edit), then one statement clears the flag of the workspace's other environments and moves their row versions. The
 * second save reads the first one's commit (a current read on MySQL / MariaDB, a new statement snapshot on PostgreSQL
 * READ COMMITTED) and clears it: one default. Stateless (worker-safe).
 */
final class DefaultEnvironmentSwitch
{
    /**
     * The edit page: `$environment` (stored) becomes the default, every other environment of its workspace is cleared.
     */
    public static function makeDefault(EntityManagerInterface $manager, ApiEnvironment $environment): void
    {
        $workspace = $environment->getWorkspace();
        if (!$workspace instanceof ApiWorkspace || $workspace->getId() === null || $environment->getId() === null) {
            throw new LogicException('Api Studio: the default environment needs a stored environment and workspace.');
        }

        self::lockAndClear($manager, $workspace, $environment->getId());
    }

    /**
     * The create page (1.21.1): locks the workspace row before the new environment is inserted, so a create waits for
     * any other create, edit or import of the workspace; when the new environment is the default, clears the flag of
     * every stored environment of the workspace (the new row is not stored yet, so it keeps its own).
     *
     * @internal the create page's lock and clear, not a public API of the bundle (1.21.1)
     */
    public static function prepareCreate(EntityManagerInterface $manager, ApiWorkspace $workspace, bool $default): void
    {
        if ($workspace->getId() === null) {
            throw new LogicException('Api Studio: a new environment needs a stored workspace.');
        }

        if (!$default) {
            $manager->lock($workspace, LockMode::PESSIMISTIC_WRITE);

            return;
        }

        self::lockAndClear($manager, $workspace, null);
    }

    private static function lockAndClear(EntityManagerInterface $manager, ApiWorkspace $workspace, ?int $keep): void
    {
        $manager->lock($workspace, LockMode::PESSIMISTIC_WRITE);
        $query = $manager->createQuery(sprintf(
            'UPDATE %s e SET e.isDefault = false, e.rowVersion = e.rowVersion + 1 WHERE e.workspace = :workspace AND e.isDefault = true%s',
            ApiEnvironment::class,
            $keep === null ? '' : ' AND e.id <> :id',
        ))->setParameter('workspace', $workspace->getId());
        if ($keep !== null) {
            $query->setParameter('id', $keep);
        }
        $query->execute();
    }
}
