<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched when a workspace is created from the studio, after its flush and **inside** the same database
 * transaction: a host listener (owner / creator rows) writes atomically with it, and an exception from a listener rolls
 * the workspace back.
 */
final readonly class ApiWorkspaceCreatedEvent
{
    public function __construct(
        public ApiWorkspace $workspace,
        public ApiStudioTenant $tenant,
        public ?object $user = null,
    ) {
    }
}
