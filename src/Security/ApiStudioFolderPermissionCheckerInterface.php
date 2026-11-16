<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

/**
 * Host contract (`nowo_api_studio.security.folder_permission_checker`, 1.14): folder-level sharing on top of the
 * workspace decisions of {@see ApiStudioPermissionCheckerInterface}. A folder checker can only narrow: it is asked
 * only once the workspace action is granted, for each folder from the top-level one down, and a denial on a folder
 * applies to everything below it.
 *
 * - `workspace.view` denied: the folder, its subfolders and their endpoints are hidden (tree, lists, exports) and
 *   their pages answer 404.
 * - another action denied (view granted): 403 for that action inside the folder (edit, move, run, example saves…).
 * - Unknown actions must be denied, as for the workspace checker.
 *
 * Decisions are memoised per request.
 *
 * What a folder restriction does not cover (audit RJ-M4): the workspace's environments and variables are shared by all
 * its folders, and a workspace editor can read and change them. Running an endpoint of a read-only folder with changed
 * URL / auth variables or host secrets in their own templates is refused (it needs `workspace.edit` on the workspace
 * AND on the folder), but credentials typed literally in a folder's auth configuration are readable by whoever may
 * edit that folder. Keep secrets in secret variables or host secrets, not in folder auth configuration.
 */
interface ApiStudioFolderPermissionCheckerInterface
{
    public function isFolderGranted(?object $user, ApiStudioAction $action, ApiStudioFolderRef $folder): bool;
}
