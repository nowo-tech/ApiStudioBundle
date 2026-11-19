<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * Permission double of the search fixtures: `workspace.view` denied on the workspaces named in `$hidden`, everything
 * else granted; counts the `workspace.view` calls.
 */
final class SearchPermissionDouble implements ApiStudioPermissionCheckerInterface
{
    /** @var list<string> */
    public array $hidden = [];

    /** @var list<ApiStudioAction> */
    public array $denied = [];

    /** @var list<int>|null */
    public ?array $visibleIds = null;

    public int $viewChecks = 0;

    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        if ($action === ApiStudioAction::WorkspaceView) {
            ++$this->viewChecks;
        }

        return !in_array($action, $this->denied, true)
            && !($action === ApiStudioAction::WorkspaceView && in_array($subject->workspace?->getName(), $this->hidden, true));
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        return $this->visibleIds;
    }
}
