<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;
use function is_scalar;
use function is_string;

/**
 * Host hook of the global search (1.18), dispatched once per group before anything is read: where the search runs
 * and what it may return. Outside an Api Studio route the bundle's tenant and scope listeners have not run (and nor
 * has any host guard of the studio routes), so this is where the host decides for the search request.
 *
 * - `$searchScope`: the scope id the search box sent (client input, e.g. a project id), or null (unscoped search).
 * - The bundle's defaults: the tenant of `tenancy.resolver` / `tenancy.provider` (instance tenant without tenancy);
 *   the workspace scope `$searchScope` itself when `scope.enabled` and it is a valid {@see WorkspaceScope} value, none
 *   when unscoped; any other scope id denies.
 * - A listener may {@see self::deny()} (no rows, no count, no hint), {@see self::excludeKind()} (e.g. the project
 *   feature is off), map the search scope to a workspace scope ({@see self::setWorkspaceScope()}, e.g. `<uuid>` →
 *   `project:<uuid>`), set the tenant, and give the route parameters of the links ({@see self::setRouteParameters()},
 *   e.g. `{organization}` of `ui.path` or `{project}` of `scope.path`).
 *
 * The bundle's checks still run afterwards on the result: roles, `security.access_checker`, `studio.open` (and the
 * scope checker's `studio.open` in a scope), `security.feature_gate`, then `workspace.view` per workspace and the
 * folder checker per endpoint. A listener can only narrow what they allow.
 *
 * Plain PHP (REQ-ARCH-001 rule 3): no Symfony, Doctrine or HTTP type. A new instance per dispatch (worker-safe).
 */
final class ApiStudioSearchContextEvent
{
    /** Route parameters of the bundle's links: a host value never replaces them. */
    public const RESERVED_PARAMETERS = ['id', 'workspaceId', 'serviceId'];

    private bool $denied = false;

    /** @var array<string, true> */
    private array $excluded = [];

    /** @var array<string, string> */
    private array $routeParameters = [];

    public function __construct(
        public readonly SearchKind $kind,
        public readonly ?string $searchScope,
        private ?ApiStudioTenant $tenant,
        private ?WorkspaceScope $workspaceScope,
        bool $denied = false,
    ) {
        $this->denied = $denied || !$tenant instanceof ApiStudioTenant;
    }

    /**
     * No rows for this search: the group is skipped (no count, no hint).
     */
    public function deny(): void
    {
        // @igor-ignore - A new event object per dispatch, never a shared service.
        $this->denied = true;
    }

    public function isDenied(): bool
    {
        return $this->denied || isset($this->excluded[$this->kind->value]);
    }

    /**
     * No rows of $kind (the other groups still run).
     */
    public function excludeKind(SearchKind $kind): void
    {
        // @igor-ignore - A new event object per dispatch, never a shared service.
        $this->excluded[$kind->value] = true;
    }

    public function isExcluded(SearchKind $kind): bool
    {
        return isset($this->excluded[$kind->value]);
    }

    /**
     * The tenant to search (null denies).
     */
    public function setTenant(?ApiStudioTenant $tenant): void
    {
        // @igor-ignore - A new event object per dispatch, never a shared service.
        $this->tenant = $tenant;
        if (!$tenant instanceof ApiStudioTenant) {
            // @igor-ignore - A new event object per dispatch, never a shared service.
            $this->denied = true;
        }
    }

    public function tenant(): ?ApiStudioTenant
    {
        return $this->tenant;
    }

    /**
     * The workspace scope to search (null: tenant level). Ignored without `scope.enabled` (nothing is scoped then).
     */
    public function setWorkspaceScope(?WorkspaceScope $scope): void
    {
        // @igor-ignore - A new event object per dispatch, never a shared service.
        $this->workspaceScope = $scope;
    }

    public function workspaceScope(): ?WorkspaceScope
    {
        return $this->workspaceScope;
    }

    /**
     * Parameters added to every link of the results (the placeholders of `ui.path`, or of `scope.path` in a scoped
     * search). The bundle's own (`id`, `workspaceId`, `serviceId`) are dropped.
     *
     * @param array<array-key, mixed> $parameters string names and scalar values only; others are dropped
     */
    public function setRouteParameters(array $parameters): void
    {
        $kept = [];
        foreach ($parameters as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value) && !in_array($name, self::RESERVED_PARAMETERS, true)) {
                $kept[$name] = (string) $value;
            }
        }
        // @igor-ignore - A new event object per dispatch, never a shared service.
        $this->routeParameters = $kept;
    }

    /**
     * @return array<string, string>
     */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }
}
