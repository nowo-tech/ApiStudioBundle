<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Host contract (`nowo_api_studio.scope.resolver`, 1.17): the scope of an Api Studio request, from its route (e.g.
 * `{project}` → `project:<uuid>`).
 *
 * Called once per Api Studio main request, after the tenant. **Identify only, never authorize** (that is
 * {@see ScopeAccessCheckerInterface}), but do check that the route value belongs to `$tenant` before returning a scope
 * for it. On a route mounted under `scope.path` ({@see ScopeRouteContext::$scopedMount}) null answers 404; on a
 * tenant-level route null means "no scope" (the tenant studio). Worker mode: read per call, cache nothing.
 */
interface ScopeResolverInterface
{
    public function resolve(ScopeRouteContext $route, ApiStudioTenant $tenant): ?WorkspaceScope;
}
