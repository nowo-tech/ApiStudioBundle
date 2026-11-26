<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Psr\Log\LoggerInterface;

use function count;
use function implode;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

use const DATE_ATOM;

/**
 * Doctrine adapter of the GDPR ports (1.24): {@see ApiStudioPersonalDataEraserInterface} and
 * {@see ApiStudioPersonalDataExporterInterface} on the Api Studio manager's connection (DBAL statements, no ORM unit
 * of work: nothing of the host's or the bundle's manager is flushed, cleared or reset).
 *
 * - Rows are read in keyset pages (`id > last ORDER BY id`, {@see self::BATCH_SIZE}) by the actor column and
 *   re-checked byte-exact in PHP (LQ rule: a PAD SPACE / case-insensitive collation of an older install never lets
 *   `u:usera` reach `u:UserA`'s rows). Writes then go row by row by primary key (point locks only).
 * - Each erasure batch is one write unit through the transaction-doctrine runner (savepoint-aware repair after a
 *   failure, so a deadlock never leaves the connection at a wrong nesting level). Inside a caller's transaction
 *   on the same connection (UserKit's erasure, when it shares the connection) the batch is a savepoint of it and a lock conflict propagates at once; standalone, a
 *   conflicting batch is tried {@see self::MAX_ATTEMPTS} times (it is idempotent) before the conflict propagates.
 * - `history.scope: endpoint` does not map `executed_by`; rows stored while the scope was `user` still carry it, so
 *   the column is then looked up in the schema. The same holds for the folder table while `folders.enabled` is off.
 * - Logs counts only (never actor ids, URLs or names).
 *
 * Stateless (worker-safe): the manager is resolved per call.
 *
 * @internal
 */
final readonly class DoctrineApiStudioPersonalData implements ApiStudioPersonalDataEraserInterface, ApiStudioPersonalDataExporterInterface
{
    /** Rows read (and written) per keyset page. */
    public const BATCH_SIZE = 500;

    /** Attempts of one standalone erasure batch on a lock conflict. */
    public const MAX_ATTEMPTS = 3;

    /** Longest actor id accepted: the folder creator column (the history column holds 64). */
    public const MAX_ACTOR_LENGTH = ApiFolder::MAX_CREATOR_LENGTH;

    public function __construct(
        private ApiStudioEntityManagerResolver $managers,
        private HistorySanitizer $sanitizer,
        private string $tablePrefix = 'api_studio_',
        private ?LoggerInterface $logger = null,
        private int $batchSize = self::BATCH_SIZE,
    ) {
    }

    public function erase(string $actorId): ApiStudioErasureResult
    {
        self::assertActor($actorId);
        $manager    = $this->managers->entityManager();
        $connection = $manager->getConnection();
        $writer     = new BatchWriter($connection, $this->logger, self::MAX_ATTEMPTS);

        $deleted = 0;
        $history = $this->historyTable($manager);
        if ($history !== null) {
            $delete = sprintf('DELETE FROM %s WHERE id = ?', $history);
            foreach ($this->actorPages($connection, $history, 'executed_by', $actorId, ['id']) as $rows) {
                $deleted += $writer->write(static fn (): int => BatchWriter::eachId($connection, $delete, self::ids($rows)));
            }
        }

        $anonymised = 0;
        $folders    = $this->folderTable($manager);
        if ($folders !== null) {
            // The row version moves like any other write of the row (docs/CONCURRENCY.md: host raw SQL bumps it).
            $update = sprintf('UPDATE %s SET created_by = NULL%s WHERE id = ?', $folders['table'], $folders['versioned'] ? ', row_version = row_version + 1' : '');
            foreach ($this->actorPages($connection, $folders['table'], 'created_by', $actorId, ['id']) as $rows) {
                $anonymised += $writer->write(static fn (): int => BatchWriter::eachId($connection, $update, self::ids($rows)));
            }
        }

        $this->logger?->info('Api Studio: personal data erased.', ['history_rows' => $deleted, 'folders_anonymised' => $anonymised]);

        return new ApiStudioErasureResult($deleted, $anonymised);
    }

    public function export(string $actorId): ApiStudioPersonalDataExport
    {
        self::assertActor($actorId);
        $manager    = $this->managers->entityManager();
        $connection = $manager->getConnection();
        $dates      = Type::getType(Types::DATETIME_IMMUTABLE);
        $platform   = $connection->getDatabasePlatform();

        $history = [];
        $table   = $this->historyTable($manager);
        if ($table !== null) {
            foreach ($this->actorPages($connection, $table, 'executed_by', $actorId, ['id', 'endpoint_id', 'executed_at', 'request_method', 'request_url', 'response_status']) as $rows) {
                foreach ($rows as $row) {
                    $executedAt = $dates->convertToPHPValue($row['executed_at'], $platform);
                    $history[]  = [
                        'id'          => self::int($row['id']),
                        'endpoint_id' => self::int($row['endpoint_id']),
                        'method'      => self::string($row['request_method']),
                        // Masked again on the way out (rows stored by older versions, new secret-like names).
                        'url'         => $this->sanitizer->sanitizeUrl(self::string($row['request_url'])),
                        'status'      => is_numeric($row['response_status']) ? (int) $row['response_status'] : null,
                        'executed_at' => $executedAt instanceof DateTimeInterface ? $executedAt->format(DATE_ATOM) : '',
                    ];
                }
            }
        }

        $folders = [];
        $table   = $this->folderTable($manager);
        if ($table !== null) {
            foreach ($this->actorPages($connection, $table['table'], 'created_by', $actorId, ['id', 'name', 'service_id', 'created_at']) as $rows) {
                foreach ($rows as $row) {
                    $createdAt = $dates->convertToPHPValue($row['created_at'], $platform);
                    $folders[] = [
                        'id'         => self::int($row['id']),
                        'name'       => self::string($row['name']),
                        'service_id' => self::int($row['service_id']),
                        'created_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DATE_ATOM) : '',
                    ];
                }
            }
        }

        $this->logger?->info('Api Studio: personal data exported.', ['history_rows' => count($history), 'folders' => count($folders)]);

        return new ApiStudioPersonalDataExport($history, $folders);
    }

    /**
     * Keyset pages of the rows whose $column equals $actorId byte for byte (empty pages are skipped).
     *
     * @param list<string> $columns
     *
     * @return iterable<list<array<string, mixed>>>
     */
    private function actorPages(Connection $connection, string $table, string $column, string $actorId, array $columns): iterable
    {
        $select = $connection->getDatabasePlatform()->modifyLimitQuery(
            sprintf('SELECT %s, %s AS actor_id FROM %s WHERE %s = ? AND id > ? ORDER BY id', implode(', ', $columns), $column, $table, $column),
            $this->batchSize,
        );
        $last = 0;
        do {
            $rows  = $connection->fetchAllAssociative($select, [$actorId, $last]);
            $exact = [];
            foreach ($rows as $row) {
                $last = self::int($row['id']);
                if ($row['actor_id'] === $actorId) {
                    $exact[] = $row;
                }
            }
            if ($exact !== []) {
                yield $exact;
            }
        } while (count($rows) === $this->batchSize);
    }

    /**
     * The history table when it has the actor column (mapped with `history.scope: user`, or left by an earlier one).
     */
    private function historyTable(EntityManagerInterface $manager): ?string
    {
        $metadata = $manager->getClassMetadata(ApiRequestHistory::class);
        $table    = $metadata->getTableName();
        if ($metadata->hasField('executedBy')) {
            return $table;
        }

        return self::hasColumn($manager->getConnection(), $table, 'executed_by') ? $table : null;
    }

    /**
     * The folder table (mapped with `folders.enabled`, or left by an earlier configuration) and whether it has the
     * row version column (1.23+).
     *
     * @return array{table: string, versioned: bool}|null
     */
    private function folderTable(EntityManagerInterface $manager): ?array
    {
        $metadata = $manager->getClassMetadata(ApiFolder::class);
        if (!$metadata->isMappedSuperclass) {
            return ['table' => $metadata->getTableName(), 'versioned' => $metadata->hasField('rowVersion')];
        }

        // Folders off: TablePrefixSubscriber skips the class, so the table name is built from the prefix.
        $table      = $this->tablePrefix . 'folder';
        $connection = $manager->getConnection();
        if (!self::hasColumn($connection, $table, 'created_by')) {
            return null;
        }

        return ['table' => $table, 'versioned' => self::hasColumn($connection, $table, 'row_version')];
    }

    private static function hasColumn(Connection $connection, string $table, string $column): bool
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist([$table])) {
            return false;
        }
        foreach ($schema->listTableColumns($table) as $candidate) {
            if (strtolower($candidate->getName()) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every id the bundle can store (audit ZCL-H1): the default id is `u:` + the raw user identifier, so spaces and
     * non-ASCII characters are legitimate (`u:John Smith`, `u:josé@example.test`). Accepted: valid UTF-8, 1 to 128
     * characters (the folder column; history ids are at most 64), no control character. Exactness comes from the
     * binary column and the `===` re-check, not from the alphabet.
     */
    private static function assertActor(string $actorId): void
    {
        if (preg_match('/^[^\x00-\x1F\x7F]{1,' . self::MAX_ACTOR_LENGTH . '}$/Du', $actorId) !== 1) {
            throw new InvalidArgumentException('Api Studio: an actor id is 1 to 128 UTF-8 characters without control characters.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = self::int($row['id']);
        }

        return $ids;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
