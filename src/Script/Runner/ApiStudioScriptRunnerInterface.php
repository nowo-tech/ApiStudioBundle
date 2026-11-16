<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * Server-side script runner (spec 003, 1.15): runs the pre / post scripts of one phase outside the browser, for
 * unattended runs (collection runner, CLI, monitors). Value objects only: no Doctrine entity, no HTTP type.
 *
 * Implementations must be stateless between runs (FrankenPHP worker mode, kernel reset off) and must enforce the
 * limits of the request themselves. The bundle ships {@see ProcessScriptRunner} (a sidecar process per run) and
 * {@see NullScriptRunner} (scripts not run, the default).
 */
interface ApiStudioScriptRunnerInterface
{
    public function run(ScriptRunRequest $request): ScriptRunResult;
}
