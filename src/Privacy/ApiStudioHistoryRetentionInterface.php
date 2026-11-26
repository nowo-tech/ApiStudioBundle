<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use DateTimeImmutable;

/**
 * Request history retention (1.24, `nowo_api_studio.history.retention_days`, default 90, 0 = keep forever): deletes
 * the rows executed before the cut-off in small batches, one short transaction each, oldest first.
 *
 * Safe to run from cron (`nowo:api-studio:history:purge`) or Messenger ({@see PurgeApiStudioHistory}), several at
 * once and repeatedly: a batch deletes rows by primary key only (no range locks on the live end of the table), a lock
 * conflict on a batch is retried a few times, a batch that another run already deleted counts 0.
 */
interface ApiStudioHistoryRetentionInterface
{
    /**
     * @param int|null $maxBatches stop after this many batches (null = until no row is older than the cut-off)
     * @param DateTimeImmutable|null $now reference time (null = now)
     *
     * @throws HistoryPurgeInsideTransactionException when called inside an open transaction (the batches must commit
     *                                                one by one)
     */
    public function purge(?int $maxBatches = null, ?DateTimeImmutable $now = null): ApiStudioHistoryPurgeResult;

    /**
     * Rows a purge at $now would delete (0 when the retention is disabled). Read-only.
     */
    public function expiredRows(?DateTimeImmutable $now = null): int;

    /**
     * Rows executed before this time are purged (null: retention disabled, `retention_days: 0`).
     */
    public function cutoff(?DateTimeImmutable $now = null): ?DateTimeImmutable;
}
