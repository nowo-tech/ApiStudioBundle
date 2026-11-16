<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderRef;

use function in_array;

/**
 * Folder checker of the functional tests (1.14): folder ids listed in $hidden are not viewable, ids in $readOnly
 * allow view and run only. Public properties so a test can set them on the container's instance.
 */
final class TestFolderChecker implements ApiStudioFolderPermissionCheckerInterface
{
    /** @var list<int> */
    public array $hidden = [];

    /** @var list<int> */
    public array $readOnly = [];

    public function isFolderGranted(?object $user, ApiStudioAction $action, ApiStudioFolderRef $folder): bool
    {
        if (in_array($folder->folderId, $this->hidden, true)) {
            return false;
        }

        return !in_array($folder->folderId, $this->readOnly, true) || in_array($action, [ApiStudioAction::WorkspaceView, ApiStudioAction::WorkspaceRun], true);
    }
}
