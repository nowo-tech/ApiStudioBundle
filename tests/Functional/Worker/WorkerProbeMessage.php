<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

/**
 * 1.12.1 (REQ-RUNTIME-002): a Messenger message of the worker-state test. A failing one changes the workspace name in
 * memory and dies before it flushes.
 */
final readonly class WorkerProbeMessage
{
    public function __construct(
        public int $workspaceId,
        public bool $fail,
        public string $dirtyName = '',
    ) {
    }
}
