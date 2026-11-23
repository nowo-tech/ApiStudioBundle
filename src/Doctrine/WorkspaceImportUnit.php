<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\FolderTreeConflictException;
use Throwable;

/**
 * One import (OpenAPI, Postman, environment variables) as one write unit (1.21.1, REQ-RUNTIME-003 rules 1 and 4): one
 * transaction that locks the workspace row first (`SELECT … FOR UPDATE`, parent before child like the edit pages and
 * the seed API), then the service row for an import into a service (1.14, audit RJ-D-L1), runs the import, flushes and
 * commits. Two imports into one workspace run one after the other, and the second one reads the rows of the first one
 * after the lock, so it merges into them instead of inserting duplicates (and keeps one default environment).
 *
 * On any failure only the levels this unit opened are rolled back, savepoint-aware; when MySQL / MariaDB already rolled
 * the whole transaction back after a deadlock (the flush's savepoint is gone) the connection is closed so its nesting
 * level is 0 again (audit ZH-M1). The original failure is rethrown. Stateless (worker-safe).
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
     * (deadlock, lock wait timeout, serialization failure, or the savepoint a deadlock destroyed; audit RJ-D2-L1), or a
     * unique key that refused a row another writer stored meanwhile below the workspace (a variable saved from an
     * environment's edit page, which locks the environment row only): the retry reads that row after the lock and
     * merges into it. Writers that insert a row of the workspace itself (a service, an environment) already wait for
     * the workspace lock: their foreign key check locks the workspace row. An import never inserts a duplicate of its
     * own (it looks its rows up in the loaded collections, new rows included).
     */
    public static function isRetryable(Throwable $failure): bool
    {
        if (FolderTreeConflictException::wrap($failure) instanceof RetryableException) {
            return true;
        }
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }
}
