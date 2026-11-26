<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the history retention for a {@see PurgeApiStudioHistory} message (1.24; registered when symfony/messenger is
 * installed). Idempotent: running it twice, or in two workers at once, deletes each expired row once. A lock conflict
 * that outlasts the retention's own retries propagates, so Messenger's retry strategy (or the failure transport)
 * takes over. Stateless (worker-safe).
 *
 * @internal
 */
#[AsMessageHandler]
final readonly class PurgeApiStudioHistoryHandler
{
    public function __construct(
        private ApiStudioHistoryRetentionInterface $retention,
    ) {
    }

    public function __invoke(PurgeApiStudioHistory $message): void
    {
        $this->retention->purge($message->maxBatches);
    }
}
