<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Query\ApiWorkspaceView;
use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * 1.19 (DDD-host checklist B1/B8): whether a user may do a workspace action, asked by ids. A host with a private entity
 * manager (`nowo_api_studio.entity_manager`) checks access without loading the bundle's entities and without DQL over
 * its tables (e.g. a host route guard that only has a workspace or endpoint id; see
 * `ApiWorkspaceQueryInterface::workspaceIdOfEndpoint()`).
 *
 * The user is:
 * - your security user object: checked as the studio checks it (the checkers receive the object, and
 *   {@see ApiStudioSubject::$actorId} its actor id);
 * - a string: the opaque actor id (`history.actor_resolver`, else `u:` + the user identifier; 1–64 printable ASCII
 *   characters, no spaces). The checkers then receive `$user = null` and the id in {@see ApiStudioSubject::$actorId}:
 *   your checker must decide on ids (a checker that reads only `$user` sees an anonymous user and should deny);
 * - null: anonymous.
 *
 * The decision is the studio's for that workspace, in this order, each able only to refuse:
 * 1. the workspace exists in `$tenant` (exact id, exact tenant: SQL, then `===` in PHP);
 * 2. for a user object or null, `visibleWorkspaceIds()` of the permission checker lists it (when it returns a list);
 * 3. a scoped workspace needs {@see ScopeAccessCheckerInterface} to grant `workspace.view` and the action (deny by
 *    default without a scope checker);
 * 4. {@see ApiStudioPermissionCheckerInterface} grants `workspace.view`, then the action, with a subject built from ids
 *    ({@see ApiStudioSubject::forWorkspaceId()}: `$workspace` is null).
 *
 * Not part of it: the request-level gates of the studio routes (`security.access_roles`, `security.access_checker`,
 * `security.feature_gate`, `studio.open`), which need the request and the firewall. Tenant-level actions
 * (`studio.open`, `workspace.create`) are always refused here.
 *
 * **No oracle**: an unknown id, a workspace of another tenant, a look-alike tenant, an unusable actor id, a refusal and
 * a failure of the store, of the actor resolver or of a host checker (logged with its class only) all give the same
 * `false` / `null`.
 *
 * Every call reads the workspace and asks the checkers again; nothing is kept between calls (FrankenPHP worker mode,
 * kernel reset false). An answer holds as of the check only (REQ-RUNTIME-003): the studio's own controllers check again
 * on every request.
 */
interface ApiWorkspaceAccessInterface
{
    /**
     * Whether $user may do $action on workspace $workspaceId of $tenant.
     *
     * @param object|string|null $user your security user, an opaque actor id, or null (anonymous)
     */
    public function isGranted(object|string|null $user, ApiStudioAction $action, ApiStudioTenant $tenant, int $workspaceId): bool;

    /**
     * The workspace as scalars when $user may view it ({@see self::isGranted()} with `workspace.view`), else null.
     *
     * @param object|string|null $user your security user, an opaque actor id, or null (anonymous)
     */
    public function viewableWorkspace(object|string|null $user, ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView;
}
