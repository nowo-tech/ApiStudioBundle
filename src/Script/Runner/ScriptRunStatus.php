<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * Outcome of a server-side script run (spec 003, 1.15).
 */
enum ScriptRunStatus: string
{
    /** Every script ran without throwing. */
    case Completed = 'completed';

    /** A script threw (or the runner answered something unusable): see the error. */
    case Failed = 'failed';

    /** The wall time limit was reached: the runner was killed. */
    case TimedOut = 'timed_out';

    /** The runner wrote more than the output limit: it was killed. */
    case OutputExceeded = 'output_exceeded';

    /** No server runner is configured: the scripts were not run. */
    case NotRun = 'not_run';
}
