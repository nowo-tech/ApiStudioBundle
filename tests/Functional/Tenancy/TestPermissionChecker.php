<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Tenancy;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * Grants everything except the actions listed in {@see self::$denied}; {@see self::$visible} narrows the lists. The
 * test drives one instance (the kernel is not rebooted between requests).
 */
final class TestPermissionChecker implements ApiStudioPermissionCheckerInterface
{
    /** @var list<ApiStudioAction> */
    public array $denied = [];

    /** @var list<int>|null */
    public ?array $visible = null;

    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        return !in_array($action, $this->denied, true);
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        return $this->visible;
    }
}
