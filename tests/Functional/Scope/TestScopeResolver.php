<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Nowo\ApiStudioBundle\Scope\ScopeResolverInterface;
use Nowo\ApiStudioBundle\Scope\ScopeRouteContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * `project:<project>` from the `{project}` placeholder; `nobody` has no scope (404 on the scoped mount). Records the
 * contexts it saw so a test can check what the port receives.
 */
final class TestScopeResolver implements ScopeResolverInterface
{
    /** @var list<array{ScopeRouteContext, ApiStudioTenant}> */
    public array $seen = [];

    public function resolve(ScopeRouteContext $route, ApiStudioTenant $tenant): ?WorkspaceScope
    {
        $this->seen[] = [$route, $tenant];
        $project      = $route->parameter('project');

        return $project !== null && $project !== 'nobody' ? new WorkspaceScope('project:' . $project) : null;
    }
}
