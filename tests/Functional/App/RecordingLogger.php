<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

use function count;
use function is_string;

/**
 * The `logger` of {@see EntityManagerTestKernel}: keeps every record in memory (1.13 tests assert that no resolved
 * host secret reaches a log record) and prints nothing.
 */
final class RecordingLogger extends AbstractLogger implements DebugLoggerInterface
{
    /** @var list<array{channel: ?string, context: array<string, mixed>, message: string, priority: int, priorityName: string, timestamp: int, timestamp_rfc3339: string}> */
    private array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $keyed = [];
        foreach ($context as $key => $value) {
            $keyed[(string) $key] = $value;
        }
        $this->records[] = ['channel' => null, 'context' => $keyed, 'message' => (string) $message, 'priority' => 0, 'priorityName' => is_string($level) ? $level : 'log', 'timestamp' => 0, 'timestamp_rfc3339' => ''];
    }

    public function getLogs(?Request $request = null): array
    {
        return $this->records;
    }

    public function countErrors(?Request $request = null): int
    {
        return count($this->records);
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
