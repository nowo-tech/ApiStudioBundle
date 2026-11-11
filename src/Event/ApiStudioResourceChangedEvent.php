<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

/**
 * Dispatched after the studio created, updated, deleted or imported a resource and the change was flushed (1.12,
 * architecture bar rules 4 and 7): ids and scalars only, never the entity or a secret value. Listen to it for audit
 * logs, search indexes, cache invalidation or an outbox.
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

    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_DELETED  = 'deleted';
    public const ACTION_IMPORTED = 'imported';

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
    ) {
    }
}
