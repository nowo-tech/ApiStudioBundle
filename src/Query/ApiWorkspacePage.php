<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

use function intdiv;

/**
 * 1.19 (DDD-host checklist B7): one page of {@see ApiWorkspaceQueryInterface::find()} and the total of the criteria.
 */
final readonly class ApiWorkspacePage
{
    /**
     * @param list<ApiWorkspaceView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $limit,
    ) {
    }

    /**
     * Number of pages of the total (0 when there is nothing).
     */
    public function pages(): int
    {
        return intdiv($this->total + $this->limit - 1, $this->limit);
    }
}
