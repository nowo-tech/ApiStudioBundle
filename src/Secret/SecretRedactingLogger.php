<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

use function is_array;
use function is_string;

/**
 * Masks resolved host secrets in the message and the string context values of every record before it reaches the
 * host logger (1.13). Built per execution, never shared.
 *
 * @internal
 */
final class SecretRedactingLogger extends AbstractLogger
{
    private readonly HistorySanitizer $sanitizer;

    /**
     * @param list<string> $values
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly array $values,
    ) {
        $this->sanitizer = new HistorySanitizer();
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $this->mask((string) $message), $this->maskContext($context));
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function maskContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->mask($value);
            } elseif (is_array($value)) {
                $context[$key] = $this->maskContext($value);
            }
        }

        return $context;
    }

    private function mask(string $text): string
    {
        return (string) $this->sanitizer->maskValues($text, $this->values);
    }
}
