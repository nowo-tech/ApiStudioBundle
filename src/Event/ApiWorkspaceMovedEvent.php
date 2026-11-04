<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched by `nowo:api-studio:tenant:move` after a workspace moved to another tenant (host rows, caches).
 */
final readonly class ApiWorkspaceMovedEvent
{
    public function __construct(
        public int $workspaceId,
        public ApiStudioTenant $from,
        public ApiStudioTenant $to,
    ) {
    }
}
