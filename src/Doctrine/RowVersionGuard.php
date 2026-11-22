<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;

use function is_numeric;
use function spl_object_id;
use function sprintf;

/**
 * Row versions of the edit forms (1.20, REQ-RUNTIME-003 rule 4). Two atomic statements on the row's `row_version`:
 *
 * - {@see claim()}: `UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?` before the edit's
 *   flush, in the edit's transaction. One row changed: the edit owns the row (its lock is held until the commit) and
 *   the entity carries the new version. No row: someone saved (or deleted) it after the form was rendered —
 *   {@see StaleEditException}, nothing written. A second editor racing on the same version waits for the first one's
 *   row lock, then matches no row (MySQL / MariaDB re-read the row for an UPDATE, PostgreSQL re-checks the condition on
 *   the new row version), so exactly one of them saves.
 * - {@see bump()}: `UPDATE … SET row_version = row_version + 1 WHERE id = ?` for writers that carry no version (seed
 *   API, documentation, folder moves, variables), so a form rendered before them is refused instead of overwriting
 *   their change ({@see RowVersionListener}).
 *
 * Works on the manager it is given (the host's or the bundle's own one), never flushes, clears or resets it.
 * Stateless (worker-safe).
 */
final class RowVersionGuard
{
    /**
     * @throws StaleEditException when the row is no longer at `$expected`
     */
    public static function claim(EntityManagerInterface $manager, RowVersionedInterface $row, int $expected): void
    {
        [$sql, $id] = self::statement($manager, $row, true);
        $changed    = (int) $manager->getConnection()->executeStatement($sql, [$id, $expected]);
        if ($changed !== 1) {
            throw new StaleEditException();
        }

        // Recorded as a change of the entity (the flush writes the same value): the listener knows the row is claimed.
        $row->markRowVersion($expected + 1);
    }

    /** Moves the row's version on by one (atomic) and records the stored value as the entity's loaded one. */
    public static function bump(EntityManagerInterface $manager, RowVersionedInterface $row): void
    {
        [$sql, $id] = self::statement($manager, $row, false);
        $connection = $manager->getConnection();
        if ((int) $connection->executeStatement($sql, [$id]) !== 1) {
            return; // Deleted meanwhile (the delete wins); nothing to record.
        }

        $version = self::storedVersion($manager, $row);
        if ($version !== null) {
            $row->markRowVersion($version);
            $manager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($row), 'rowVersion', $version);
        }
    }

    /** The version the database holds for the row (this transaction's view), null when the row is gone. */
    public static function storedVersion(EntityManagerInterface $manager, RowVersionedInterface $row): ?int
    {
        [$select, $id] = self::statement($manager, $row, null);
        $version       = $manager->getConnection()->fetchOne($select, [$id]);

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * The SQL (claim: guarded UPDATE, bump: plain UPDATE, null: SELECT of the version) on the row's table, with the
     * table and column names of its metadata (table prefix and quoting included), and the row id.
     *
     * @return array{string, int}
     */
    private static function statement(EntityManagerInterface $manager, RowVersionedInterface $row, ?bool $guarded): array
    {
        $id = $row->getId();
        if ($id === null) {
            throw new LogicException('Api Studio: a row version needs a stored row.');
        }

        $metadata = $manager->getClassMetadata($row::class);
        $platform = $manager->getConnection()->getDatabasePlatform();
        $quotes   = $manager->getConfiguration()->getQuoteStrategy();
        $table    = $quotes->getTableName($metadata, $platform);
        $idColumn = $quotes->getColumnName($metadata->getSingleIdentifierFieldName(), $metadata, $platform);
        $column   = $quotes->getColumnName('rowVersion', $metadata, $platform);

        if ($guarded === null) {
            return [sprintf('SELECT %s FROM %s WHERE %s = ?', $column, $table, $idColumn), $id];
        }

        $sql = sprintf('UPDATE %1$s SET %2$s = %2$s + 1 WHERE %3$s = ?', $table, $column, $idColumn);

        return [$guarded ? $sql . sprintf(' AND %s = ?', $column) : $sql, $id];
    }
}
