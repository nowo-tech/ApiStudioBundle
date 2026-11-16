<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

use InvalidArgumentException;

/**
 * Limits of one server-side run (`scripts.limits`, spec 003 FR-SCR-004).
 */
final readonly class ScriptRunLimits
{
    public function __construct(
        public int $timeMs = 2000,
        public int $memoryMb = 64,
        public int $outputKb = 256,
        public int $sendRequests = 10,
    ) {
        if ($timeMs < 1 || $memoryMb < 1 || $outputKb < 1 || $sendRequests < 0) {
            throw new InvalidArgumentException('Script run limits must be positive.');
        }
    }
}
