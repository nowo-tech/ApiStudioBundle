<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderRef;

use function in_array;

/**
 * Folder double: `workspace.view` denied on the folder ids in `$hidden`; counts the calls.
 */
final class SearchFolderDouble implements ApiStudioFolderPermissionCheckerInterface
{
    /** @var list<int> */
    public array $hidden = [];

    public int $checks = 0;

    public function isFolderGranted(?object $user, ApiStudioAction $action, ApiStudioFolderRef $folder): bool
    {
        ++$this->checks;

        return !in_array($folder->folderId, $this->hidden, true);
    }
}
