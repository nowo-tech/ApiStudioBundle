<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * The workspaces the current request may see: those of the tenant (when tenancy is on), narrowed to `visibleIds`
 * when the permission checker lists them (null = all of the tenant).
 *
 * 1.17 (`scope.enabled`, `$scopes` true): narrowed by the host scope too, compared byte-exact —
 * - a scoped request (`$hostScope` set) sees only the workspaces of that scope;
 * - a tenant-level request sees the unscoped workspaces, plus (`$tenantListAll`, `scope.tenant_list: all`) those of
 *   `$visibleScopes` (null = every scope).
 */
final readonly class ApiStudioScope
{
    /**
     * @param list<int>|null $visibleIds
     * @param list<string>|null $visibleScopes
     */
    public function __construct(
        public ApiStudioTenant $tenant,
        public bool $tenancy = false,
        public ?array $visibleIds = null,
        public ?WorkspaceScope $hostScope = null,
        public bool $scopes = false,
        public bool $tenantListAll = true,
        public ?array $visibleScopes = null,
    ) {
    }

    public static function unrestricted(): self
    {
        return new self(ApiStudioTenant::instance());
    }

    public function contains(ApiWorkspace $workspace): bool
    {
        if ($this->tenancy && !$workspace->getTenant()->equals($this->tenant)) {
            return false;
        }

        if (!$this->containsScope($workspace->getScopeKey())) {
            return false;
        }

        return $this->visibleIds === null || in_array($workspace->getId(), $this->visibleIds, true);
    }

    /**
     * 1.17: whether a workspace stored with scope `$key` (`''` = tenant level) belongs to this request's view.
     */
    public function containsScope(string $key): bool
    {
        if (!$this->scopes) {
            return true;
        }

        if ($this->hostScope instanceof WorkspaceScope) {
            return $key === $this->hostScope->value;
        }

        if ($key === '') {
            return true;
        }

        return $this->tenantListAll && ($this->visibleScopes === null || in_array($key, $this->visibleScopes, true));
    }
}
