<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiScriptVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Throwable;

use function count;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function strlen;

/**
 * `pm.globals` (workspace) and `pm.collectionVariables` (service) of sandboxed scripts (spec 003, 1.15).
 *
 * Reads and writes go through DBAL, one statement per key and no unit of work: a concurrent insert of the same key
 * ends as an update instead of a closed entity manager (worker mode), and nothing is kept between requests.
 */
final class ScriptVariableStore
{
    /** Most variables kept per scope (workspace globals, or one service's collection variables). */
    public const MAX_PER_SCOPE = 500;

    /** Most changes accepted in one save. */
    public const MAX_CHANGES = 100;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Values of one scope, by key ($serviceId null: the workspace globals).
     *
     * @return array<string, string>
     */
    public function values(int $workspaceId, ?int $serviceId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            sprintf('SELECT variable_key, value FROM %s WHERE workspace_id = ? AND scope_id = ? ORDER BY variable_key', $this->table()),
            [$workspaceId, $serviceId ?? 0],
            [Types::INTEGER, Types::INTEGER],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['variable_key']] = (string) $row['value'];
        }

        return $values;
    }

    /**
     * Saves the script changes of one run (audit RW-L3): workspace globals and the service's collection variables in one
     * transaction, all or nothing. A string sets the key, null removes it. The workspace row is locked first (a no-op
     * update, a row lock on MySQL / PostgreSQL and the write lock on SQLite), so two concurrent saves of the same
     * workspace cannot both pass the per-scope cap.
     *
     * @param array<mixed, mixed> $globals
     * @param array<mixed, mixed> $collection
     *
     * @throws InvalidArgumentException on a malformed change, too many changes or a full scope (nothing written)
     *
     * @return int the number of keys written or removed
     */
    public function save(int $workspaceId, int $serviceId, array $globals, array $collection): int
    {
        // Cheap checks before any lock.
        $this->checkChanges($globals);
        $this->checkChanges($collection);
        $connection = $this->entityManager->getConnection();

        $connection->beginTransaction();
        try {
            $connection->executeStatement(
                sprintf('UPDATE %s SET id = id WHERE id = ?', $this->entityManager->getClassMetadata(ApiWorkspace::class)->getTableName()),
                [$workspaceId],
                [Types::INTEGER],
            );
            // Both scopes are checked against the cap under the lock before anything is written.
            $globalChanges     = $this->normalize($workspaceId, null, $globals);
            $collectionChanges = $this->normalize($workspaceId, $serviceId, $collection);
            $count             = $this->apply($connection, $workspaceId, null, $globalChanges)
                + $this->apply($connection, $workspaceId, $serviceId, $collectionChanges);
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return $count;
    }

    /**
     * @param array<string, string|null> $normalized
     */
    private function apply(Connection $connection, int $workspaceId, ?int $serviceId, array $normalized): int
    {
        $table = $this->table();
        foreach ($normalized as $key => $value) {
            if ($value === null) {
                $connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE workspace_id = ? AND scope_id = ? AND variable_key = ?', $table),
                    [$workspaceId, $serviceId ?? 0, $key],
                    [Types::INTEGER, Types::INTEGER, Types::STRING],
                );

                continue;
            }
            $this->write($table, $workspaceId, $serviceId, $key, $value);
        }

        return count($normalized);
    }

    /**
     * Shape and size of one scope's changes: string keys, string or null values, at most MAX_CHANGES, values at most
     * MAX_VALUE_BYTES bytes (a TEXT column holds 65,535 bytes, not characters).
     *
     * @param array<mixed, mixed> $changes
     */
    private function checkChanges(array $changes): void
    {
        if (count($changes) > self::MAX_CHANGES) {
            throw new InvalidArgumentException(sprintf('At most %d variable changes per run.', self::MAX_CHANGES));
        }
        foreach ($changes as $key => $value) {
            if (!is_string($key) || ($value !== null && !is_string($value))) {
                throw new InvalidArgumentException('Invalid variable change.');
            }
            if ($value !== null && strlen($value) > ApiScriptVariable::MAX_VALUE_BYTES) {
                throw new InvalidArgumentException(sprintf('Variable "%s" is too long (at most %d bytes).', mb_substr($key, 0, 64), ApiScriptVariable::MAX_VALUE_BYTES));
            }
        }
    }

    /**
     * @param array<mixed, mixed> $changes
     *
     * @return array<string, string|null>
     */
    private function normalize(int $workspaceId, ?int $serviceId, array $changes): array
    {
        $this->checkChanges($changes);
        $normalized = [];
        foreach ($changes as $key => $value) {
            $key = VariableSyntax::normalizeKey((string) $key);
            if (mb_strlen($key) > ApiScriptVariable::MAX_KEY_LENGTH) {
                throw new InvalidArgumentException(sprintf('Variable names have at most %d characters.', ApiScriptVariable::MAX_KEY_LENGTH));
            }
            $normalized[$key] = $value;
        }
        if ($normalized === []) {
            return [];
        }

        $existing = $this->values($workspaceId, $serviceId);
        $new      = 0;
        foreach ($normalized as $key => $value) {
            if ($value !== null && !isset($existing[$key])) {
                ++$new;
            }
        }
        if ($new > 0 && count($existing) + $new > self::MAX_PER_SCOPE) {
            throw new InvalidArgumentException(sprintf('At most %d variables per scope.', self::MAX_PER_SCOPE));
        }

        return $normalized;
    }

    private function write(string $table, int $workspaceId, ?int $serviceId, string $key, string $value): void
    {
        $connection = $this->entityManager->getConnection();
        $now        = new DateTimeImmutable();
        $update     = static fn (): int => (int) $connection->executeStatement(
            sprintf('UPDATE %s SET value = ?, updated_at = ? WHERE workspace_id = ? AND scope_id = ? AND variable_key = ?', $table),
            [$value, $now, $workspaceId, $serviceId ?? 0, $key],
            [Types::TEXT, Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::INTEGER, Types::STRING],
        );
        if ($update() > 0) {
            return;
        }

        try {
            $connection->insert($table, [
                'workspace_id' => $workspaceId,
                'service_id'   => $serviceId,
                'scope_id'     => $serviceId ?? 0,
                'variable_key' => $key,
                'value'        => $value,
                'updated_at'   => $now,
            ], [Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::STRING, Types::TEXT, Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // Inserted by a concurrent save in the meantime (or the same value already stored): last write wins.
            $update();
        }
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(ApiScriptVariable::class)->getTableName();
    }
}
