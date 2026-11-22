<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

use DateTimeImmutable;

/**
 * Dispatched after the studio created, updated, deleted or imported a resource and the change was flushed (1.12,
 * architecture bar rules 4 and 7): ids and scalars only, never the entity or a secret value. Listen to it for audit
 * logs, search indexes, cache invalidation or an outbox. Later minors add types and actions: ignore the ones you do
 * not know.
 */
final readonly class ApiStudioResourceChangedEvent
{
    public const TYPE_WORKSPACE            = 'workspace';
    public const TYPE_SERVICE              = 'service';
    public const TYPE_ENDPOINT             = 'endpoint';
    public const TYPE_ENVIRONMENT          = 'environment';
    public const TYPE_ENVIRONMENT_VARIABLE = 'environment_variables';
    public const TYPE_REQUEST_EXAMPLE      = 'request_example';
    public const TYPE_RESPONSE_EXAMPLE     = 'response_example';
    /** 1.14 (folders). */
    public const TYPE_FOLDER = 'folder';

    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_DELETED  = 'deleted';
    public const ACTION_IMPORTED = 'imported';
    /** 1.14: a folder or an endpoint moved in its service tree (another parent or another position). */
    public const ACTION_MOVED = 'moved';

    public function __construct(
        /** One of the TYPE_* constants. `environment_variables`: the variables of environment {@see self::$resourceId} were synced. */
        public string $resourceType,
        /** Id of the resource (for a deletion, the id it had). */
        public int $resourceId,
        /** Id of the workspace the resource belongs to (the workspace itself for TYPE_WORKSPACE). */
        public int $workspaceId,
        /** One of the ACTION_* constants. */
        public string $action,
        /** Opaque actor id (`history.actor_resolver`, else `u:` + user identifier); null when anonymous. */
        public ?string $actorId = null,
        /** 1.19 (DDD-host checklist B4): when the bundle built the event, right before dispatching it. */
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
