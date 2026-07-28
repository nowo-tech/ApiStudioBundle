<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use function in_array;
use function preg_replace;
use function strtolower;

/**
 * Redacts secrets/PII from request history payloads before persistence (REQ-OBS-001).
 */
final class HistorySanitizer
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-access-token',
        'api-key',
        'apikey',
    ];

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function sanitizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = $this->isSensitiveHeader($name) ? self::REDACTED : $value;
        }

        return $out;
    }

    public function sanitizeBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $sanitized = preg_replace(
            '/("?(?:password|passwd|secret|token|api[_-]?key|authorization|access[_-]?token)"?\s*[:=]\s*")([^"]*)(")/i',
            '$1' . self::REDACTED . '$3',
            $body,
        );

        $sanitized = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._\-+=\/]+/i',
            '$1' . self::REDACTED,
            $sanitized ?? $body,
        );

        return $sanitized ?? $body;
    }

    private function isSensitiveHeader(string $name): bool
    {
        return in_array(strtolower($name), self::SENSITIVE_HEADERS, true);
    }
}
