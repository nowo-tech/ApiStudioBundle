<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use RuntimeException;
use Throwable;

/**
 * The target (tenant, scope) of {@see WorkspaceScopeMover::move()} already has a workspace with that slug (1.17).
 * A domain conflict: nothing was changed; rename one of them first. Not a lock conflict (no CONFLICT_00x code).
 */
final class WorkspaceSlugTakenException extends RuntimeException
{
    public function __construct(
        public readonly int $workspaceId,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Api Studio: the target scope already has a workspace with this slug.', 0, $previous);
    }
}
