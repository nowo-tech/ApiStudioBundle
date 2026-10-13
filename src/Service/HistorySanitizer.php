<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function urldecode;
use function urlencode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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

    /** Query parameter names whose values are always redacted (matched case-insensitively). */
    private const SENSITIVE_QUERY_PATTERN = '/(?:password|passwd|^pwd$|secret|token|api[_-]?key|^auth$|authorization|access[_-]?key|signature|^sig$|^key$|session[_-]?id|^sid$|credential)/i';

    /**
     * @param array<string, string> $headers
     * @param list<string> $extraSensitiveNames additional header names to redact (e.g. the service's API-key header)
     * @param list<string> $secretValues literal values to mask wherever they appear in other header values
     *
     * @return array<string, string>
     */
    public function sanitizeHeaders(array $headers, array $extraSensitiveNames = [], array $secretValues = []): array
    {
        $extra = array_map(strtolower(...), $extraSensitiveNames);
        $out   = [];
        foreach ($headers as $name => $value) {
            $sensitive  = $this->isSensitiveHeader($name) || in_array(strtolower($name), $extra, true);
            $out[$name] = $sensitive ? self::REDACTED : $this->maskSecretValues($value, $secretValues);
        }

        return $out;
    }

    /**
     * Redacts secrets from a request URL before it is stored in history: values of query parameters with
     * sensitive names (or listed in $extraSensitiveNames), userinfo passwords and any occurrence of the
     * given secret values (e.g. resolved secret environment variables).
     *
     * @param list<string> $extraSensitiveNames additional query parameter names to redact
     * @param list<string> $secretValues literal values to mask wherever they appear in the URL
     */
    public function sanitizeUrl(string $url, array $extraSensitiveNames = [], array $secretValues = []): string
    {
        $extra = array_map(strtolower(...), $extraSensitiveNames);

        // user:password@host
        $url = preg_replace('#^([a-z][a-z0-9+.\-]*://[^/@:?\#]+):[^/@?\#]*@#i', '$1:' . self::REDACTED . '@', $url) ?? $url;

        $fragment = '';
        $hashPos  = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url      = substr($url, 0, $hashPos);
        }

        $queryPos = strpos($url, '?');
        if ($queryPos !== false) {
            $pairs = explode('&', substr($url, $queryPos + 1));
            foreach ($pairs as $i => $pair) {
                $eqPos = strpos($pair, '=');
                if ($eqPos === false) {
                    continue;
                }

                $name = trim(urldecode(substr($pair, 0, $eqPos)));
                $base = strtolower((string) preg_replace('/\[.*$/', '', $name));
                if ($base !== '' && (in_array($base, $extra, true) || preg_match(self::SENSITIVE_QUERY_PATTERN, $base) === 1)) {
                    $pairs[$i] = substr($pair, 0, $eqPos + 1) . self::REDACTED;
                }
            }

            $url = substr($url, 0, $queryPos + 1) . implode('&', $pairs);
        }

        $url .= $fragment;

        return $this->maskSecretValues($url, $secretValues);
    }

    /**
     * @param list<string> $secretValues literal values to mask wherever they appear in the body
     *                                   (raw, URL-encoded or JSON-escaped)
     */
    public function sanitizeBody(?string $body, array $secretValues = []): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $body = $this->maskSecretValues($body, $secretValues);

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

    /**
     * Replaces every occurrence of the given secret values (raw, URL-encoded and JSON-escaped forms).
     *
     * @param list<string> $secretValues
     */
    private function maskSecretValues(string $value, array $secretValues): string
    {
        foreach ($secretValues as $secret) {
            if (strlen($secret) < 4) {
                // Too short to mask without mangling unrelated content.
                continue;
            }

            $forms = [$secret, rawurlencode($secret), urlencode($secret)];
            foreach ([JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES] as $flags) {
                $json = json_encode($secret, $flags);
                if (is_string($json)) {
                    $forms[] = substr($json, 1, -1);
                }
            }

            $value = str_replace(array_values(array_unique($forms)), self::REDACTED, $value);
        }

        return $value;
    }

    private function isSensitiveHeader(string $name): bool
    {
        return in_array(strtolower($name), self::SENSITIVE_HEADERS, true);
    }
}
