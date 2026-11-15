<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_walk_recursive;
use function is_scalar;

/**
 * Unit-test logger: keeps the records and can flatten them to text (1.13 host secret masking assertions).
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function text(): string
    {
        $text    = '';
        $records = $this->records;
        array_walk_recursive($records, static function (mixed $value) use (&$text): void {
            if (is_scalar($value) || $value instanceof Stringable) {
                $text .= (string) $value . "\n";
            }
        });

        return $text;
    }
}
