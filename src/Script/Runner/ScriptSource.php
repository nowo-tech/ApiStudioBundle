<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * One script of a phase and the label its log lines get (e.g. "service pre", "folder Users post").
 */
final readonly class ScriptSource
{
    public function __construct(
        public string $label,
        public string $code,
    ) {
    }
}
