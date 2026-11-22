<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use DateTimeImmutable;
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
        /** 1.19 (DDD-host checklist B4): when the bundle built the event, right before dispatching it. */
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
