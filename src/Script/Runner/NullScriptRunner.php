<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * Default server runner (no `scripts.server_runner`): unattended runs skip scripts and report them as not run.
 */
final class NullScriptRunner implements ApiStudioScriptRunnerInterface
{
    public function run(ScriptRunRequest $request): ScriptRunResult
    {
        return ScriptRunResult::unchanged($request, ScriptRunStatus::NotRun, 'No server-side script runner is configured (scripts.server_runner).');
    }
}
