<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeeder;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched by {@see WorkspaceSeeder} once a seed is **committed** (1.17): ids only, never a variable value.
 */
final readonly class ApiWorkspaceSeededEvent
{
    public function __construct(
        public int $workspaceId,
        public ApiStudioTenant $tenant,
        /** Scope value of the workspace; null at tenant level. */
        public ?string $scope,
        /** True when the seed created the workspace, false when it updated it. */
        public bool $created,
        /** 1.19 (DDD-host checklist B4): when the bundle built the event, right before dispatching it. */
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
