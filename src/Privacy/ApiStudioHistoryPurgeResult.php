<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use DateTimeImmutable;

/**
 * Outcome of one {@see ApiStudioHistoryRetentionInterface::purge()} run (1.24).
 */
final readonly class ApiStudioHistoryPurgeResult
{
    /**
     * @param int $deleted rows deleted by this run
     * @param int $batches batches run
     * @param bool $complete no row older than the cut-off was left when the run stopped (false: `maxBatches` reached)
     * @param DateTimeImmutable|null $cutoff rows executed before it were purged (null: retention disabled)
     */
    public function __construct(
        public int $deleted,
        public int $batches,
        public bool $complete,
        public ?DateTimeImmutable $cutoff,
    ) {
    }

    public static function disabled(): self
    {
        return new self(0, 0, true, null);
    }
}
