<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Psr\Log\LoggerInterface;

use function count;
use function is_numeric;
use function sprintf;

use const DATE_ATOM;

/**
 * Doctrine adapter of {@see ApiStudioHistoryRetentionInterface} (1.24). Each batch reads the ids of the oldest rows
 * before the cut-off (index `idx_history_executed_at`, no lock), then deletes them by primary key in one short write
 * unit ({@see BatchWriter}): no range or next-key lock on the live end of the table, so request executions keep
 * writing while it runs (REQ-RUNTIME-003 rule 9). Refuses to run inside an open transaction, where the batches would
 * pile up their locks until the caller commits. Logs the cut-off and the counts only. Stateless (worker-safe).
 *
 * @internal
 */
final readonly class DoctrineApiStudioHistoryRetention implements ApiStudioHistoryRetentionInterface
{
    /** Attempts of one batch on a lock conflict before the conflict propagates. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private ApiStudioEntityManagerResolver $managers,
        private int $retentionDays = 90,
        private int $batchSize = 500,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function purge(?int $maxBatches = null, ?DateTimeImmutable $now = null): ApiStudioHistoryPurgeResult
    {
        if ($this->retentionDays <= 0) {
            return ApiStudioHistoryPurgeResult::disabled();
        }

        $manager    = $this->managers->entityManager();
        $connection = $manager->getConnection();
        if ($connection->isTransactionActive()) {
            throw new LogicException('Api Studio: the history purge commits batch by batch; run it outside a transaction.');
        }

        $table  = $manager->getClassMetadata(ApiRequestHistory::class)->getTableName();
        $cutoff = $this->cutoffAt($now ?? new DateTimeImmutable());
        $before = Type::getType(Types::DATETIME_IMMUTABLE)->convertToDatabaseValue($cutoff, $connection->getDatabasePlatform());
        $select = $connection->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id FROM %s WHERE executed_at < ? ORDER BY executed_at, id', $table), $this->batchSize);
        // One statement per row, by primary key: point locks only. An `IN (…)` list may be run as a table scan that
        // locks every row it reads (MariaDB on a small table), so a live writer's row would make the batch wait.
        $delete   = sprintf('DELETE FROM %s WHERE id = ?', $table);
        $writer   = new BatchWriter($connection, $this->logger, self::MAX_ATTEMPTS);
        $deleted  = 0;
        $batches  = 0;
        $complete = false;

        $this->logger?->info('Api Studio: history purge started.', ['cutoff' => $cutoff->format(DATE_ATOM), 'batch_size' => $this->batchSize]);
        while ($maxBatches === null || $batches < $maxBatches) {
            $ids = [];
            foreach ($connection->fetchFirstColumn($select, [$before]) as $id) {
                $ids[] = is_numeric($id) ? (int) $id : 0;
            }
            if ($ids === []) {
                $complete = true;

                break;
            }

            $deleted += $writer->write(static fn (): int => BatchWriter::eachId($connection, $delete, $ids));
            ++$batches;
            if (count($ids) < $this->batchSize) {
                $complete = true;

                break;
            }
        }
        $this->logger?->info('Api Studio: history purge finished.', ['deleted' => $deleted, 'batches' => $batches, 'complete' => $complete]);

        return new ApiStudioHistoryPurgeResult($deleted, $batches, $complete, $cutoff);
    }

    public function expiredRows(?DateTimeImmutable $now = null): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $manager    = $this->managers->entityManager();
        $connection = $manager->getConnection();
        $table      = $manager->getClassMetadata(ApiRequestHistory::class)->getTableName();
        $before     = Type::getType(Types::DATETIME_IMMUTABLE)->convertToDatabaseValue($this->cutoff($now), $connection->getDatabasePlatform());
        $count      = $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE executed_at < ?', $table), [$before]);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function cutoff(?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        return $this->retentionDays <= 0 ? null : $this->cutoffAt($now ?? new DateTimeImmutable());
    }

    private function cutoffAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(sprintf('P%dD', $this->retentionDays)));
    }
}
