<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Search;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Scope\ScopeLabelProviderInterface;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function array_values;
use function implode;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function strcspn;
use function substr;
use function trim;

/**
 * Turns the scalar rows of the search queries into {@see SearchHit}s (1.18): workspace / service / endpoint names, the
 * service protocol, the endpoint method and path (cut at `?` / `#` and to {@see self::MAX_PATH_LENGTH} characters, so a
 * literal query string never shows), the workspace's scope label. The rows carry nothing else: no base URL, header,
 * auth, body, script or variable column is read by the queries.
 *
 * Links: the bundle's show pages at tenant level; in a scoped search, their twins under `scope.path` when it is
 * mounted. Scope labels come from one {@see ScopeLabelProviderInterface::labels()} call per group. Stateless.
 */
final readonly class SearchHitMapper
{
    public const MAX_PATH_LENGTH = 120;

    public const ICONS = [
        'workspaces' => 'tabler:briefcase',
        'services'   => 'tabler:server',
        'endpoints'  => 'tabler:route',
    ];

    public function __construct(
        private UrlGeneratorInterface $router,
        private ?ScopeLabelProviderInterface $scopeLabels = null,
        /** `scope.path` is mounted (the twin routes exist). */
        private bool $scopedRoutes = false,
    ) {
    }

    /**
     * The hit of one row.
     *
     * @param array<string, mixed> $row a row of the kind's searchQuery()
     * @param array<string, string> $routeParameters the host's link parameters
     * @param array<string, string> $labels scope value => label ({@see self::labels()}); a missing one shows the raw value
     */
    public function hit(SearchKind $kind, array $row, ?WorkspaceScope $scope, array $routeParameters = [], array $labels = []): SearchHit
    {
        $stored = is_string($row['wscope'] ?? null) ? $row['wscope'] : '';
        // Inside a scope every row has that scope: no badge.
        $label = $stored === '' || $scope instanceof WorkspaceScope ? null : ($labels[$stored] ?? $stored);

        return match ($kind) {
            SearchKind::Workspace => new SearchHit(
                $kind,
                self::int($row['wid'] ?? null),
                self::text($row['wname'] ?? null),
                '',
                $this->url('nowo_api_studio_workspace_show', ['id' => self::int($row['wid'] ?? null)], $scope, $routeParameters),
                self::ICONS[$kind->value],
                $label,
                self::date($row['wupdated'] ?? null),
            ),
            SearchKind::Service => new SearchHit(
                $kind,
                self::int($row['sid'] ?? null),
                self::text($row['sname'] ?? null),
                self::join([self::text($row['wname'] ?? null), self::protocol($row['sprotocol'] ?? null)]),
                $this->url('nowo_api_studio_service_show', ['workspaceId' => self::int($row['wid'] ?? null), 'id' => self::int($row['sid'] ?? null)], $scope, $routeParameters),
                self::ICONS[$kind->value],
                $label,
                self::date($row['supdated'] ?? null),
            ),
            SearchKind::Endpoint => new SearchHit(
                $kind,
                self::int($row['eid'] ?? null),
                self::text($row['ename'] ?? null),
                self::join([trim(self::method($row['emethod'] ?? null) . ' ' . self::path($row['epath'] ?? null)), self::text($row['sname'] ?? null)]),
                $this->url('nowo_api_studio_endpoint_show', ['workspaceId' => self::int($row['wid'] ?? null), 'serviceId' => self::int($row['sid'] ?? null), 'id' => self::int($row['eid'] ?? null)], $scope, $routeParameters),
                self::ICONS[$kind->value],
                $label,
                self::date($row['eupdated'] ?? null),
            ),
        };
    }

    /**
     * "See all" of a group: the workspace list for workspaces; null for services and endpoints (no list page).
     *
     * @param array<string, string> $routeParameters
     */
    public function moreUrl(SearchKind $kind, ?WorkspaceScope $scope, array $routeParameters = []): ?string
    {
        return $kind === SearchKind::Workspace ? $this->url('nowo_api_studio_workspace_index', [], $scope, $routeParameters) : null;
    }

    /**
     * The endpoint path as shown: up to the first `?` or `#`, at most {@see self::MAX_PATH_LENGTH} characters.
     */
    public static function path(mixed $path): string
    {
        $path = self::text($path);
        $path = substr($path, 0, strcspn($path, '?#'));

        return mb_strlen($path) > self::MAX_PATH_LENGTH ? mb_substr($path, 0, self::MAX_PATH_LENGTH - 1) . '…' : $path;
    }

    /**
     * @param array<string, int> $parameters
     * @param array<string, string> $routeParameters
     */
    private function url(string $route, array $parameters, ?WorkspaceScope $scope, array $routeParameters): string
    {
        $name = $scope instanceof WorkspaceScope && $this->scopedRoutes ? ApiStudioRoutes::scopedName($route) : $route;

        return $this->router->generate($name, [...$routeParameters, ...$parameters]);
    }

    /**
     * Labels of the scopes of $rows, in one {@see ScopeLabelProviderInterface::labels()} call (none without a provider
     * or a scoped row).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    public function labels(array $rows, ApiStudioTenant $tenant): array
    {
        if (!$this->scopeLabels instanceof ScopeLabelProviderInterface) {
            return [];
        }
        $scopes = [];
        foreach ($rows as $row) {
            $value = $row['wscope'] ?? null;
            if (is_string($value) && $value !== '' && preg_match(WorkspaceScope::PATTERN, $value) === 1) {
                $scopes[$value] = new WorkspaceScope($value);
            }
        }

        return $scopes === [] ? [] : $this->scopeLabels->labels(array_values($scopes), $tenant);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null;
    }

    private static function protocol(mixed $value): string
    {
        $protocol = $value instanceof ApiProtocol ? $value : (is_string($value) ? ApiProtocol::tryFrom($value) : null);

        return match ($protocol) {
            ApiProtocol::Rest    => 'REST',
            ApiProtocol::Soap    => 'SOAP',
            ApiProtocol::Graphql => 'GraphQL',
            null                 => '',
        };
    }

    private static function method(mixed $value): string
    {
        $method = $value instanceof HttpMethod ? $value : (is_string($value) ? HttpMethod::tryFrom($value) : null);

        return $method instanceof HttpMethod ? $method->value : '';
    }

    /**
     * @param list<string> $parts
     */
    private static function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }
}
