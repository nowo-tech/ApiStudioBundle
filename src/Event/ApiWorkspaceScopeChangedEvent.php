<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

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
    ) {
    }
}
