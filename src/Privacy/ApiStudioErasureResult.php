<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

/**
 * What one {@see ApiStudioPersonalDataEraserInterface::erase()} call changed (1.24).
 */
final readonly class ApiStudioErasureResult
{
    /**
     * @param int $historyRowsDeleted request history rows of the actor deleted
     * @param int $foldersAnonymised folders whose `created_by` was the actor, now null
     */
    public function __construct(
        public int $historyRowsDeleted = 0,
        public int $foldersAnonymised = 0,
    ) {
    }

    public function with(self $other): self
    {
        return new self($this->historyRowsDeleted + $other->historyRowsDeleted, $this->foldersAnonymised + $other->foldersAnonymised);
    }
}
