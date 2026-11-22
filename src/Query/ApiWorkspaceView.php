<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * 1.19 (DDD-host checklist B7): a workspace as scalars, never the entity. No script, variable or secret is ever part
 * of it. Read at the moment of the query: a snapshot, not a lock (REQ-RUNTIME-003).
 */
final readonly class ApiWorkspaceView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public bool $enabled,
        public ApiStudioTenant $tenant,
        /** Scope value (`scope.enabled`); null at tenant level. */
        public ?string $scope,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
