<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Default checker (no `security.permission_checker`): whoever passed the role check and the legacy access checker may
 * do everything, on every workspace (1.10 behaviour).
 */
final readonly class LegacyPermissionChecker implements ApiStudioPermissionCheckerInterface
{
    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        return true;
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        return null;
    }
}
