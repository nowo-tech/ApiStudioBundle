<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

/**
 * Messenger message (1.24): run the history retention ({@see ApiStudioHistoryRetentionInterface}) in a worker, e.g.
 * from a Symfony Scheduler schedule. Handled by {@see PurgeApiStudioHistoryHandler} when symfony/messenger is
 * installed. Idempotent: a redelivered or duplicated message deletes nothing more than the first one.
 */
final readonly class PurgeApiStudioHistory
{
    /**
     * @param int|null $maxBatches stop after this many batches (null = until done)
     */
    public function __construct(
        public ?int $maxBatches = null,
    ) {
    }
}
