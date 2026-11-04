<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched after a workspace was deleted from the studio (informational: the bundle's own rows are gone through
 * foreign-key cascades; a host's rows should cascade too).
 */
final readonly class ApiWorkspaceDeletedEvent
{
    public function __construct(
        public int $workspaceId,
        public ApiStudioTenant $tenant,
    ) {
    }
}
