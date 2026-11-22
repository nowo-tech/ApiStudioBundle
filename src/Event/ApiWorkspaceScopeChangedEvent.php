<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched by {@see WorkspaceScopeMover} once a workspace's scope change is **committed** (1.17): ids only.
 */
final readonly class ApiWorkspaceScopeChangedEvent
{
    public function __construct(
        public int $workspaceId,
        public ApiStudioTenant $tenant,
        /** Previous scope value; null = tenant level. */
        public ?string $from,
        /** New scope value; null = tenant level. */
        public ?string $to,
        /** 1.19 (DDD-host checklist B4): when the bundle built the event, right before dispatching it. */
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
