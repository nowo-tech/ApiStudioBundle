<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\FolderTreeConflictException;
use Throwable;

use function sprintf;

/**
 * One import (OpenAPI, Postman, environment variables) as one write unit (1.21.1, REQ-RUNTIME-003 rules 1 and 4): one
 * transaction that locks, in this order, the workspace row (`SELECT … FOR UPDATE`, parent before child like the edit
 * pages and the seed API), the service row for an import into a service (1.14, audit RJ-D-L1), and the stored
 * environments of the workspace in id order (the import may write variables of any of them: the target of an
 * environment import, the workspace payload's environments, Postman's collection variables), then runs the import,
 * flushes and commits.
 *
 * Every other writer of these rows takes the same order or a suffix of it: an environment's edit page locks its own row
 * (after the workspace when it sets the default), and a create of a service or an environment locks the workspace row
 * through its foreign key check. So two imports, or an import and an edit, run one after the other, and the later one
 * reads the earlier one's rows after the lock and merges into them: no duplicate, one default environment, and no
 * deadlock between them. A unique violation that still happens is therefore treated as deterministic (see
 * {@see isRetryable()}).
 *
 * On any failure only the levels this unit opened are rolled back, savepoint-aware; when MySQL / MariaDB already rolled
 * the whole transaction back after a deadlock (the flush's savepoint is gone) the connection is closed so its nesting
 * level is 0 again (audit ZH-M1). The original failure is rethrown. Stateless (worker-safe).
 *
 * @internal the import pages' write unit, not a public API of the bundle (1.21.1)
 */
final class WorkspaceImportUnit
{
    /**
     * @template T
     *
     * @param callable(): T $import
     *
     * @return T
     */
    public static function run(EntityManagerInterface $manager, ApiWorkspace $workspace, ?ApiService $service, callable $import): mixed
    {
        $connection = $manager->getConnection();
        $level      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $manager->lock($workspace, LockMode::PESSIMISTIC_WRITE);
            if ($service instanceof ApiService && $service->getId() !== null) {
                $manager->lock($service, LockMode::PESSIMISTIC_WRITE);
            }
            // Fixed order (by id), so two units never lock two environments the other way round.
            $manager->createQuery(sprintf('SELECT e.id FROM %s e WHERE e.workspace = :workspace ORDER BY e.id ASC', ApiEnvironment::class))
                ->setParameter('workspace', $workspace->getId())
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getScalarResult();
            $result = $import();
            $manager->flush();
            $connection->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                while ($connection->getTransactionNestingLevel() > $level) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                $connection->close();
            }

            throw $e;
        }
    }

    /**
     * Whether a failure of {@see run()} is worth a retry (the import page answers 409 + `Retry-After`): a lock conflict
     * only — a deadlock, a lock wait timeout, a serialization failure, or the savepoint a deadlock destroyed (audit
     * RJ-D2-L1). A unique violation is not: under the locks of {@see run()} no other writer can store the same row
     * meanwhile, so it comes from the import itself (two names that give the same slug once truncated, keys that a
     * case-insensitive column folds together) and the same import would be refused again; the page answers it as an
     * import error (422), as before 1.21.1.
     */
    public static function isRetryable(Throwable $failure): bool
    {
        return FolderTreeConflictException::wrap($failure) instanceof RetryableException;
    }
}
