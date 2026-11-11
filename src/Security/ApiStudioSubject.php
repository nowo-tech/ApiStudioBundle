<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * What an {@see ApiStudioAction} applies to: the tenant, and the workspace for workspace actions. The bundle never
 * passes a workspace of another tenant. Later minors may append fields (e.g. a folder).
 *
 * 1.12: {@see self::$workspaceId} carries the workspace id for permission checkers that decide by id (DDD hosts:
 * grants stored against the id, no bundle entity in the host domain); `$workspace` stays for standard hosts.
 */
final readonly class ApiStudioSubject
{
    /** Id of {@see self::$workspace} (null for tenant-level actions). */
    public ?int $workspaceId;

    public function __construct(
        public ApiStudioTenant $tenant,
        public ?ApiWorkspace $workspace = null,
    ) {
        $this->workspaceId = $workspace?->getId();
    }
}
