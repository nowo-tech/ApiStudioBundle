<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\IdOnlyHost;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * 1.19 (B10): the permission checker of a DDD host — decides on ids only (the subject's tenant key, actor id and
 * workspace id against {@see HostGrantStore}); it never reads `$user` or the subject's entity. Tenant-level actions
 * need a known actor; a workspace needs a grant of the actor in that tenant.
 */
final readonly class HostGrantChecker implements ApiStudioPermissionCheckerInterface
{
    public function __construct(
        private HostGrantStore $grants,
    ) {
    }

    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        if ($subject->actorId === null) {
            return false;
        }

        if (!$action->needsWorkspace()) {
            return true;
        }

        return $subject->workspaceId !== null && $this->grants->has($subject->tenant->key(), $subject->actorId, $subject->workspaceId);
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        // Decided per workspace (an id-only host has no user object to list by).
        return null;
    }
}
