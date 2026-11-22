<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * What a scope decision applies to (1.17): the tenant, the scope and — for workspace actions — the workspace id. Ids
 * only. Later minors may append fields.
 *
 * 1.19: {@see self::$actorId}, the opaque actor id of the user (the same as `ApiStudioSubject::$actorId`), so a scope
 * checker can decide on ids alone (`ApiWorkspaceAccessInterface` with an actor id passes `$user = null`).
 */
final readonly class ScopeAccessSubject
{
    public function __construct(
        public ApiStudioTenant $tenant,
        public WorkspaceScope $scope,
        /** Null for tenant-level actions (studio.open, workspace.create). */
        public ?int $workspaceId = null,
        /** 1.19: opaque actor id of the user; null: anonymous, or no id could be resolved. */
        public ?string $actorId = null,
    ) {
    }
}
