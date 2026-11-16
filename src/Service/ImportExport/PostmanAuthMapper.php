<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Nowo\ApiStudioBundle\Enum\AuthType;

use function is_array;
use function is_scalar;
use function strtolower;

/**
 * Postman `auth` objects (v2.0 maps and v2.1 key / value lists) as Api Studio auth (1.14). Only `noauth`, `basic`,
 * `bearer` and `apikey` are mapped; any other type answers null (reported by the importer, never guessed).
 */
final class PostmanAuthMapper
{
    /**
     * @return array{type: AuthType, config: array<string, string>}|null
     */
    public static function map(mixed $auth): ?array
    {
        if (!is_array($auth)) {
            return null;
        }

        $type   = strtolower((string) ($auth['type'] ?? ''));
        $params = self::params($auth[$type] ?? []);

        return match ($type) {
            'noauth' => ['type' => AuthType::None, 'config' => []],
            'basic'  => ['type' => AuthType::Basic, 'config' => ['username' => $params['username'] ?? '', 'password' => $params['password'] ?? '']],
            'bearer' => ['type' => AuthType::Bearer, 'config' => ['token' => $params['token'] ?? '']],
            'apikey' => strtolower($params['in'] ?? 'header') === 'header'
                ? ['type' => AuthType::ApiKey, 'config' => ['header' => $params['key'] ?? 'X-Api-Key', 'value' => $params['value'] ?? '']]
                : null,
            default => null,
        };
    }

    /**
     * Whether $auth is a Postman auth object at all (present and typed).
     */
    public static function isPresent(mixed $auth): bool
    {
        return is_array($auth) && (string) ($auth['type'] ?? '') !== '';
    }

    /**
     * @return array<string, string>
     */
    private static function params(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $params = [];
        foreach ($raw as $key => $value) {
            // v2.1: [{key, value, type}]; v2.0: {name: value}.
            if (is_array($value) && isset($value['key'])) {
                $params[(string) $value['key']] = is_scalar($value['value'] ?? null) ? (string) $value['value'] : '';
            } elseif (is_scalar($value)) {
                $params[(string) $key] = (string) $value;
            }
        }

        return $params;
    }
}
