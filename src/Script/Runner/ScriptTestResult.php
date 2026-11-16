<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * One `pm.test()` outcome.
 */
final readonly class ScriptTestResult
{
    public function __construct(
        public string $name,
        public bool $ok,
        public ?string $error = null,
    ) {
    }
}
