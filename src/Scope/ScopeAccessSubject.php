<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * What a scope decision applies to (1.17): the tenant, the scope and — for workspace actions — the workspace id. Ids
 * only. Later minors may append fields.
 */
final readonly class ScopeAccessSubject
{
    public function __construct(
        public ApiStudioTenant $tenant,
        public WorkspaceScope $scope,
        /** Null for tenant-level actions (studio.open, workspace.create). */
        public ?int $workspaceId = null,
    ) {
    }
}
