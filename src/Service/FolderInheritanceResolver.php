<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\AuthType;

use function array_merge;
use function array_reverse;
use function strcasecmp;

/**
 * What an endpoint inherits from its folders (1.14): headers and auth. Pure functions over the entity graph (no state,
 * worker-safe). Without a folder (folders off, or an endpoint at the service root) the result is exactly 1.13's: the
 * service default headers merged with the endpoint headers, and the service auth.
 */
final class FolderInheritanceResolver
{
    /**
     * Stored headers of the endpoint: service defaults, then each folder from the top-level one down, then the
     * endpoint. With folders, header names compare case-insensitively and the deepest level wins.
     *
     * @return array<string, string>
     *
     * @phpstan-impure
     */
    public static function headers(ApiService $service, ApiEndpoint $endpoint): array
    {
        $folders = $endpoint->getFolder()?->getPath() ?? [];
        if ($folders === []) {
            return array_merge($service->getDefaultHeaders(), $endpoint->getHeaders());
        }

        $headers = $service->getDefaultHeaders();
        foreach ($folders as $folder) {
            $headers = self::mergeHeaders($headers, $folder->getHeaders());
        }

        return self::mergeHeaders($headers, $endpoint->getHeaders());
    }

    /**
     * The auth the endpoint is sent with: the nearest folder with an explicit auth type (none included), else the
     * service's.
     *
     * @return array{type: AuthType, config: array<string, mixed>, folder: ApiFolder|null}
     *
     * @phpstan-impure
     */
    public static function auth(ApiService $service, ApiEndpoint $endpoint): array
    {
        foreach (array_reverse($endpoint->getFolder()?->getPath() ?? []) as $folder) {
            $type = $folder->getAuthType();
            if ($type instanceof AuthType) {
                return ['type' => $type, 'config' => $folder->getAuthConfig(), 'folder' => $folder];
            }
        }

        return ['type' => $service->getAuthType(), 'config' => $service->getAuthConfig(), 'folder' => null];
    }

    /**
     * $over replaces the entries of $base with the same name, compared case-insensitively.
     *
     * @param array<string, string> $base
     * @param array<string, string> $over
     *
     * @return array<string, string>
     */
    public static function mergeHeaders(array $base, array $over): array
    {
        foreach ($over as $name => $value) {
            foreach (array_keys($base) as $existing) {
                if (strcasecmp((string) $existing, (string) $name) === 0) {
                    unset($base[$existing]);
                }
            }
            $base[(string) $name] = $value;
        }

        return $base;
    }
}
