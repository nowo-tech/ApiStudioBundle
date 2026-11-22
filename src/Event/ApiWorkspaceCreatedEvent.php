<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use DateTimeImmutable;
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
 *
 * 1.19 (DDD-host checklist B4): `$workspace` and `$user` are deprecated (kept in 1.x; no removal planned): read
 * {@see self::$workspaceId}, {@see self::$slug}, {@see self::$tenant}, {@see self::$scope} and {@see self::$actorId}.
 * A listener that only records ids, or talks to another system, belongs on {@see ApiWorkspaceCreatedCommittedEvent}:
 * this event fires before the commit, so a later rollback can still remove the workspace.
 */
final readonly class ApiWorkspaceCreatedEvent
{
    /** Id of the created workspace (set: dispatched after the flush). */
    public ?int $workspaceId;

    /** Slug of the created workspace. */
    public string $slug;

    public function __construct(
        /** @deprecated since 1.19, use $workspaceId / $slug (the entity of the bundle's manager) */
        public ApiWorkspace $workspace,
        public ApiStudioTenant $tenant,
        /** @deprecated since 1.19, use $actorId (the host's security user object) */
        public ?object $user = null,
        /** Opaque actor id of the creator (`history.actor_resolver`, else `u:` + user identifier); null when anonymous. */
        public ?string $actorId = null,
        /** 1.17: scope value of the workspace (`scope.enabled`); null at tenant level. */
        public ?string $scope = null,
        /** 1.19 (DDD-host checklist B4): when the bundle built the event, right before dispatching it. */
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
        $this->workspaceId = $workspace->getId();
        $this->slug        = $workspace->getSlug();
    }
}
