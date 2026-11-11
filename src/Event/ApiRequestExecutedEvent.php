<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Event;

/**
 * Dispatched after the studio executed a request and stored its history row (1.12, architecture bar rules 4 and 7).
 * Ids and scalars only: no URL path or query, no headers, no bodies and no variable values (they may carry secrets);
 * the host name of the target is the only part of the URL.
 */
final readonly class ApiRequestExecutedEvent
{
    public function __construct(
        public int $endpointId,
        public ?int $serviceId,
        public ?int $workspaceId,
        public ?int $environmentId,
        public ?int $historyId,
        /** HTTP method sent (e.g. GET, POST). */
        public string $method,
        /** Host of the target URL (lower case), null when the request could not be built. */
        public ?string $host,
        /** Response status, null when no response was received. */
        public ?int $statusCode,
        public int $durationMs,
        public bool $success,
        /** Opaque actor id; null when anonymous. */
        public ?string $actorId = null,
    ) {
    }
}
