<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Host contract (`nowo_api_studio.security.permission_checker`): who may do what, per workspace. Called after the role
 * check and the legacy {@see ApiStudioAccessCheckerInterface}; decisions are memoised per request.
 *
 * - Not granted `workspace.view`: the workspace answers 404 (as if it did not exist). Viewable but not granted the
 *   action: 403. Pages hide the actions that are not granted.
 * - Unknown actions must be denied.
 */
interface ApiStudioPermissionCheckerInterface
{
    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool;

    /**
     * Workspaces of $tenant the user may view, for lists, counts and the sidebar. null = every workspace of the tenant,
     * [] = none.
     *
     * @return list<int>|null
     */
    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array;
}
