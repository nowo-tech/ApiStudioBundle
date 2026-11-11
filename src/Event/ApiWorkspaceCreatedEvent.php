<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Dispatched when a workspace is created from the studio, after its flush and **inside** the same database
 * transaction: a host listener (owner / creator rows) writes atomically with it, and an exception from a listener rolls
 * the workspace back. Listeners that talk to the outside world (outbox relays, mails, other systems) should listen to
 * {@see ApiWorkspaceCreatedCommittedEvent} instead, dispatched once the transaction committed (1.12).
 *
 * 1.12: carries the ids too ({@see self::$workspaceId}, {@see self::$slug}, {@see self::$actorId}) for hosts that
 * reference the workspace by id; `$workspace` and `$user` stay for standard hosts.
 */
final readonly class ApiWorkspaceCreatedEvent
{
    /** Id of the created workspace (set: dispatched after the flush). */
    public ?int $workspaceId;

    /** Slug of the created workspace. */
    public string $slug;

    public function __construct(
        public ApiWorkspace $workspace,
        public ApiStudioTenant $tenant,
        public ?object $user = null,
        /** Opaque actor id of the creator (`history.actor_resolver`, else `u:` + user identifier); null when anonymous. */
        public ?string $actorId = null,
    ) {
        $this->workspaceId = $workspace->getId();
        $this->slug        = $workspace->getSlug();
    }
}
