<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * Permission checker double: grants everything except `$denied`, records the subjects.
 */
final class RecordingPermissionChecker implements ApiStudioPermissionCheckerInterface
{
    /** @var list<ApiStudioAction> */
    public array $denied = [];

    /** @var list<ApiStudioSubject> */
    public array $subjects = [];

    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        $this->subjects[] = $subject;

        return !in_array($action, $this->denied, true);
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        return null;
    }
}
