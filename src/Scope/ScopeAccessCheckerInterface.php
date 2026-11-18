<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Host contract (`nowo_api_studio.scope.access_checker`, 1.17, mandatory with scopes on): who may do what in a scope.
 * Consulted on top of {@see ApiStudioPermissionCheckerInterface} (both must grant) for every action in a scoped request
 * and for scoped workspaces opened from the tenant level.
 *
 * - `studio.open` / `workspace.view` denied: 404 (the scope or the workspace does not exist for the user). Other
 *   actions: 403 (or 404 with `security.access_denied_response: 404`).
 * - **Deny by default:** unknown actions (later minors may add cases) must be denied. An exception propagates (the
 *   request fails closed).
 * - Worker mode: no per-request state in the service; decisions are memoised per request by the bundle.
 */
interface ScopeAccessCheckerInterface
{
    public function isGranted(?object $user, ApiStudioAction $action, ScopeAccessSubject $subject): bool;

    /**
     * Scope values of `$tenant` whose workspaces the user may see on tenant-level lists (`scope.tenant_list: all`).
     * null = every scope, [] = none (only unscoped workspaces are listed). At most 10 000 values.
     *
     * @return list<string>|null
     */
    public function visibleScopes(?object $user, ApiStudioTenant $tenant): ?array;
}
