<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * What an {@see ApiStudioAction} applies to: the tenant, and the workspace for workspace actions. The bundle never
 * passes a workspace of another tenant. Later minors may append fields (e.g. a folder).
 *
 * 1.12: {@see self::$workspaceId} carries the workspace id for permission checkers that decide by id (DDD hosts:
 * grants stored against the id, no bundle entity in the host domain); `$workspace` stays for standard hosts.
 *
 * 1.19 (DDD-host checklist B1/B8): {@see self::forWorkspaceId()} builds a subject from ids only, and
 * {@see self::$actorId} carries the opaque actor id of the user (the id history rows and events record), so a checker
 * can decide on ids alone. {@see ApiWorkspaceAccessInterface} passes such subjects: `$workspace` is then null.
 */
final readonly class ApiStudioSubject
{
    /** Id of the workspace (null for tenant-level actions, and for a workspace not flushed yet). */
    public ?int $workspaceId;

    /**
     * @param ApiWorkspace|null $workspace deprecated since 1.19, decide on {@see self::$workspaceId}: it is null in the
     *                                     subjects of {@see ApiWorkspaceAccessInterface}; kept for standard hosts (no
     *                                     removal planned in 1.x)
     * @param int|null $workspaceId 1.19: the workspace id without the entity (must equal the entity's id when both are
     *                              given)
     * @param string|null $actorId 1.19: opaque actor id of the user (`history.actor_resolver`, else `u:` + the user
     *                             identifier); null: anonymous, or no id could be resolved
     *
     * @throws InvalidArgumentException for a workspace id below 1, or one that is not the entity's
     */
    public function __construct(
        public ApiStudioTenant $tenant,
        /** @deprecated since 1.19, use $workspaceId */
        public ?ApiWorkspace $workspace = null,
        /** 1.17: the host scope the action applies to (the workspace's, else the request's); null at tenant level. */
        public ?WorkspaceScope $scope = null,
        ?int $workspaceId = null,
        public ?string $actorId = null,
    ) {
        if ($workspaceId !== null && ($workspaceId < 1 || ($workspace instanceof ApiWorkspace && $workspace->getId() !== $workspaceId))) {
            throw new InvalidArgumentException('Api Studio subject: the workspace id must be a positive integer and the id of the workspace given.');
        }

        $this->workspaceId = $workspace instanceof ApiWorkspace ? $workspace->getId() : $workspaceId;
    }

    /**
     * 1.19: a workspace action's subject from ids only (no entity).
     *
     * @throws InvalidArgumentException for a workspace id below 1
     */
    public static function forWorkspaceId(ApiStudioTenant $tenant, int $workspaceId, ?WorkspaceScope $scope = null, ?string $actorId = null): self
    {
        return new self($tenant, null, $scope, $workspaceId, $actorId);
    }

    /**
     * 1.19: a tenant-level action's subject (`studio.open`, `workspace.create`).
     */
    public static function forTenant(ApiStudioTenant $tenant, ?WorkspaceScope $scope = null, ?string $actorId = null): self
    {
        return new self($tenant, null, $scope, null, $actorId);
    }
}
