<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched once a workspace created from the studio is **committed** (1.12, architecture bar rule 7): ids only, for
 * outbox relays and integration listeners that must never see a workspace a later rollback removes. The in-transaction
 * {@see ApiWorkspaceCreatedEvent} stays for host writes that must be atomic with the workspace.
 */
final readonly class ApiWorkspaceCreatedCommittedEvent
{
    public function __construct(
        public int $workspaceId,
        public string $slug,
        public ApiStudioTenant $tenant,
        /** Opaque actor id of the creator; null when anonymous. */
        public ?string $actorId = null,
    ) {
    }
}
