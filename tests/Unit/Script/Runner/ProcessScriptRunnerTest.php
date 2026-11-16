<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Script\Runner;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Script\Runner\NullScriptRunner;
use Nowo\ApiStudioBundle\Script\Runner\ProcessScriptRunner;
use Nowo\ApiStudioBundle\Script\Runner\ScriptRunLimits;
use Nowo\ApiStudioBundle\Script\Runner\ScriptRunRequest;
use Nowo\ApiStudioBundle\Script\Runner\ScriptRunStatus;
use Nowo\ApiStudioBundle\Script\Runner\ScriptSource;
use Nowo\ApiStudioBundle\Script\Runner\ScriptTestResult;
use Nowo\ApiStudioBundle\Script\ScriptRequestSender;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function file_get_contents;
use function gc_collect_cycles;
use function glob;
use function hrtime;
use function is_dir;
use function is_file;
use function memory_get_usage;
use function preg_split;
use function putenv;
use function str_repeat;
use function trim;
use function usleep;

use const PHP_BINARY;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Spec 003 (1.15): the reference server runner against a fake sidecar (tests/Fixtures/runner/fake-runner.php):
 * protocol, limits, pm.sendRequest relay, environment scrubbing, worker-mode loop.
 */
final class ProcessScriptRunnerTest extends TestCase
{
    private const FIXTURE    = __DIR__ . '/../../../Fixtures/runner/fake-runner.php';
    private const FIXTURE_SH = __DIR__ . '/../../../Fixtures/runner/fake-runner.sh';

    protected function tearDown(): void
    {
        putenv('API_STUDIO_RUNNER_TEST_SECRET'); // @phpstan-ignore frankenphp.classic.noPutenv (CLI test process)
    }

    public function testCompletedRunMapsTheRunnerState(): void
    {
        putenv('API_STUDIO_RUNNER_TEST_SECRET=REDACTED-TEST'); // @phpstan-ignore frankenphp.classic.noPutenv (CLI test process)
        $result = $this->runner('echo')->run($this->request(new ScriptRunLimits(timeMs: 20_000, memoryMb: 48, sendRequests: 4)));

        self::assertSame(ScriptRunStatus::Completed, $result->status);
        self::assertTrue($result->isCompleted());
        self::assertNull($result->error);
        // scripts | first label | {memory_mb} | {runner} | environment scrubbed | send budget
        self::assertSame('2|service pre|48|api-script-runner.js|no-secret|4', $result->variables['seen']);
        self::assertSame('abc', $result->variables['token']);
        self::assertSame(['seen' => $result->variables['seen']], $result->changedVariables);
        self::assertSame(['Accept' => 'application/json', 'X-Run' => '1'], $result->headers);
        self::assertSame(['page' => '1'], $result->queryParams);
        self::assertSame('{}', $result->body);
        self::assertSame(['[pre] hello'], $result->logs);
        self::assertEquals([new ScriptTestResult('ok', true), new ScriptTestResult('bad', false, 'boom')], $result->tests);
        self::assertSame(['g' => 'G'], $result->globals);
        self::assertSame(['c' => '1'], $result->collectionVariables);
        self::assertSame(['g' => 'G', 'gone' => null], $result->globalChanges);
        self::assertSame([], $result->collectionChanges);
    }

    public function testFailedScript(): void
    {
        $result = $this->runner('fail')->run($this->request());

        self::assertSame(ScriptRunStatus::Failed, $result->status);
        self::assertSame('TypeError: x is not a function', $result->error);
        self::assertSame('pre', $result->failedScript);
    }

    public function testLimitsAndBrokenRunners(): void
    {
        $started = hrtime(true);
        $timeout = $this->runner('sleep')->run($this->request(new ScriptRunLimits(timeMs: 300)));
        self::assertSame(ScriptRunStatus::TimedOut, $timeout->status);
        self::assertSame('Scripts exceeded the 300 ms time limit.', $timeout->error);
        self::assertLessThan(5_000_000_000, hrtime(true) - $started, 'the sidecar is killed at the limit');
        self::assertSame('abc', $timeout->variables['token'], 'the request state is kept');

        $memory = memory_get_usage();
        $flood  = $this->runner('flood')->run($this->request(new ScriptRunLimits(timeMs: 20_000, outputKb: 64)));
        self::assertSame(ScriptRunStatus::OutputExceeded, $flood->status);
        self::assertSame('Scripts exceeded the 64 KB output limit.', $flood->error);
        self::assertLessThan(1024 * 1024, memory_get_usage() - $memory, 'a flooding sidecar never fills PHP memory');

        // A sidecar that never reads its input cannot stall the runner on a large run message.
        $started = hrtime(true);
        $deaf    = $this->runner('deaf')->run(new ScriptRunRequest(scripts: [new ScriptSource('pre', '1')], body: str_repeat('x', 1024 * 1024), limits: new ScriptRunLimits(timeMs: 500)));
        self::assertSame(ScriptRunStatus::TimedOut, $deaf->status);
        self::assertLessThan(5_000_000_000, hrtime(true) - $started);

        $exit = $this->runner('exit')->run($this->request());
        self::assertSame(ScriptRunStatus::Failed, $exit->status);
        self::assertSame('The script runner exited without a result (exit code 255).', $exit->error);

        $invalid = $this->runner('invalid')->run($this->request());
        self::assertSame('The script runner answered an invalid result.', $invalid->error);

        $missing = (new ProcessScriptRunner(['/nonexistent/api-studio-runner'], 'runner.js'))->run($this->request());
        self::assertSame(ScriptRunStatus::Failed, $missing->status);
        self::assertStringContainsString('script runner', (string) $missing->error);
    }

    /**
     * Audit RW-L2: pm.sendRequest gets only what is left of the run's time, and the run stops once it is used up.
     */
    public function testSendRequestIsBoundedByTheRunDeadline(): void
    {
        $durations = [];
        $client    = new MockHttpClient(static function (string $method, string $url, array $options) use (&$durations): MockResponse {
            $durations[] = $options['max_duration'];
            usleep(2_500_000); // a slow upstream: the mock does not enforce max_duration itself

            return new MockResponse('{}');
        });
        $sender  = new ScriptRequestSender($client, new ExecutionUrlValidator([]), timeoutSeconds: 30);
        $started = hrtime(true);
        $result  = $this->runner('send', $sender)->run($this->request(new ScriptRunLimits(timeMs: 2000, sendRequests: 5)));

        self::assertSame(ScriptRunStatus::TimedOut, $result->status);
        self::assertCount(1, $durations, 'no further call once the time is up');
        self::assertLessThanOrEqual(2.0, $durations[0]);
        self::assertGreaterThan(0.0, $durations[0]);
        self::assertLessThan(5_000_000_000, hrtime(true) - $started);
    }

    public function testSendRequestIsRelayedThroughTheGuardedSender(): void
    {
        $sender = new ScriptRequestSender(new MockHttpClient(new MockResponse('{}', ['http_code' => 201])), new ExecutionUrlValidator([]));

        $result = $this->runner('send', $sender)->run($this->request(new ScriptRunLimits(timeMs: 20_000, sendRequests: 2)));
        self::assertSame(['code 201', 'error pm.sendRequest: request URL is not allowed (SSRF or allowlist).', 'error pm.sendRequest: request budget exhausted.'], $result->logs);

        $none = $this->runner('send')->run($this->request());
        self::assertSame('error pm.sendRequest is not available in this runner.', $none->logs[0]);
    }

    public function testRunsDoNotAccumulateInAWorker(): void
    {
        $runner = $this->runner('echo');
        $limits = new ScriptRunLimits(timeMs: 20_000);
        for ($i = 0; $i < 10; ++$i) {
            $runner->run($this->request($limits));
        }
        $before = memory_get_usage();
        for ($i = 0; $i < 60; ++$i) {
            $result = $runner->run($this->request($limits));
            self::assertTrue($result->isCompleted(), (string) $result->error);
        }

        self::assertLessThan(256 * 1024, memory_get_usage() - $before);
    }

    /**
     * SC-004: 1,000 runs in one process (a FrankenPHP worker with kernel reset off keeps the runner service alive): every
     * run completes, no sidecar outlives its run and memory stays flat.
     */
    public function testAThousandRunsInOneWorkerLeakNoProcessAndNoMemory(): void
    {
        if (!is_dir('/proc/self/task') || !is_file('/bin/sh')) {
            self::markTestSkipped('needs /proc and /bin/sh.');
        }
        $runner  = new ProcessScriptRunner(['/bin/sh', self::FIXTURE_SH], '/bundle/runner/api-script-runner.js', pollMicroseconds: 200);
        $request = new ScriptRunRequest(scripts: [new ScriptSource('pre', 'pm.environment.set("run", 1)')], limits: new ScriptRunLimits(timeMs: 20_000));
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($runner->run($request)->isCompleted());
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 1_000; ++$i) {
            $result = $runner->run($request);
            if (!$result->isCompleted() || $result->variables !== ['run' => '1']) {
                self::fail('run ' . $i . ': ' . $result->status->value . ' ' . $result->error);
            }
        }
        gc_collect_cycles();

        self::assertLessThan(512 * 1024, memory_get_usage() - $before);
        self::assertSame([], $this->childProcesses());
    }

    public function testConfigurationErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProcessScriptRunner([], 'runner.js');
    }

    public function testLimitsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScriptRunLimits(timeMs: 0);
    }

    public function testNullRunnerReportsScriptsAsNotRun(): void
    {
        $result = (new NullScriptRunner())->run($this->request());

        self::assertSame(ScriptRunStatus::NotRun, $result->status);
        self::assertStringContainsString('scripts.server_runner', (string) $result->error);
        self::assertSame(['token' => 'abc'], $result->variables);
        self::assertSame(['g' => '1'], $result->globals);
    }

    /**
     * PIDs of the live children of this process (zombies included: they are listed until reaped).
     *
     * @return list<string>
     */
    private function childProcesses(): array
    {
        $children = [];
        foreach (glob('/proc/self/task/*/children') ?: [] as $file) {
            $children = [...$children, ...preg_split('/\s+/', trim((string) file_get_contents($file)), -1, PREG_SPLIT_NO_EMPTY) ?: []];
        }

        return $children;
    }

    private function runner(string $mode, ?ScriptRequestSender $sender = null): ProcessScriptRunner
    {
        return new ProcessScriptRunner([PHP_BINARY, self::FIXTURE, $mode, ProcessScriptRunner::MEMORY_PLACEHOLDER, ProcessScriptRunner::RUNNER_PLACEHOLDER], '/bundle/runner/api-script-runner.js', $sender);
    }

    /** Generous wall time by default: a PHP sidecar under coverage can take a while to start. */
    private function request(ScriptRunLimits $limits = new ScriptRunLimits(timeMs: 20_000)): ScriptRunRequest
    {
        return new ScriptRunRequest(
            scripts: [new ScriptSource('service pre', 'pm.environment.set("a", 1)'), new ScriptSource('pre-request', '')],
            variables: ['token' => 'abc'],
            body: '{}',
            headers: ['Accept' => 'application/json'],
            queryParams: ['page' => '1'],
            globals: ['g' => '1'],
            collectionVariables: ['c' => '1'],
            runTests: true,
            limits: $limits,
        );
    }
}
