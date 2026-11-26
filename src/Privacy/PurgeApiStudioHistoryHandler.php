<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs the history retention for a {@see PurgeApiStudioHistory} message (1.24; registered when symfony/messenger is
 * installed). Idempotent: running it twice, or in two workers at once, deletes each expired row once. A lock conflict
 * that outlasts the retention's own retries propagates, so Messenger's retry strategy (or the failure transport)
 * takes over. Inside a transaction (a bus with the `doctrine_transaction` middleware) it fails unrecoverably at once. Stateless (worker-safe).
 *
 * @internal
 */
#[AsMessageHandler]
final readonly class PurgeApiStudioHistoryHandler
{
    public function __construct(
        private ApiStudioHistoryRetentionInterface $retention,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException when the bus wraps the handler in a transaction (audit ZCL-L2):
     *                                               retrying cannot help, the message goes to the failure transport
     */
    public function __invoke(PurgeApiStudioHistory $message): void
    {
        try {
            $this->retention->purge($message->maxBatches);
        } catch (HistoryPurgeInsideTransactionException $e) {
            $this->logger?->error('Api Studio: the history purge message ran inside a transaction (doctrine_transaction middleware?); route PurgeApiStudioHistory to a bus without it. Nothing was purged.', ['exception' => $e::class]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
