<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration;

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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function is_file;
use function trim;

/**
 * Spec 003 (1.15): the bundled sidecar (src/Resources/runner/api-script-runner.js) as a host runs it: Deno with no
 * permission (the default command; CI installs it) or Node 25+ with --permission. Node below 25 is refused (RW-L1).
 */
final class BundledScriptRunnerTest extends TestCase
{
    private const RUNNER = __DIR__ . '/../../src/Resources/runner/api-script-runner.js';

    private ProcessScriptRunner $runner;

    protected function setUp(): void
    {
        if ($this->name() === 'testNodeWithoutANetworkBlockIsRefused') {
            return;
        }
        if (!is_file(self::RUNNER)) {
            self::markTestSkipped('the built runner is not available.');
        }
        // Audit RW-L1: the runner refuses Node below 25 (no network block under --permission); Deno (the default
        // command, set up in CI) or Node 25+ with --permission run the scripts.
        $finder = new ExecutableFinder();
        $deno   = $finder->find('deno');
        $node   = $finder->find('node');
        if ($deno !== null) {
            $command = [$deno, 'run', '--quiet', '--no-prompt', '--no-config', '--no-lock', '--no-npm', '--no-remote', '--v8-flags=--max-old-space-size={memory_mb}', '{runner}'];
        } elseif ($node !== null && self::nodeMajor($node) >= 25) {
            $command = [$node, '--permission', '--max-old-space-size={memory_mb}', '{runner}'];
        } else {
            self::markTestSkipped('needs deno, or node 25+.');
        }
        $sender       = new ScriptRequestSender(new MockHttpClient(new MockResponse('{"items":[1,2,3]}', ['http_code' => 200])), new ExecutionUrlValidator([]));
        $this->runner = new ProcessScriptRunner($command, self::RUNNER, $sender);
    }

    /**
     * Audit RW-L1: under Node without --permission, or below 25, every run is refused with the reason.
     */
    public function testNodeWithoutANetworkBlockIsRefused(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not available.');
        }
        $runner = new ProcessScriptRunner([$node, '{runner}'], self::RUNNER);
        $result = $runner->run(new ScriptRunRequest(scripts: [new ScriptSource('pre', "fetch('http://169.254.169.254/')")], variables: ['a' => '1'], limits: new ScriptRunLimits(timeMs: 15_000)));

        self::assertSame(ScriptRunStatus::Failed, $result->status);
        self::assertMatchesRegularExpression('/^The script runner refuses Node/', (string) $result->error);
        self::assertSame(['a' => '1'], $result->variables);
        if (self::nodeMajor($node) < 25) {
            $permission = (new ProcessScriptRunner([$node, '--permission', '{runner}'], self::RUNNER))->run(new ScriptRunRequest(scripts: [new ScriptSource('pre', '1')], limits: new ScriptRunLimits(timeMs: 15_000)));
            self::assertStringContainsString('Node 25 or later is required', (string) $permission->error);
        }
    }

    public function testNetworkIsBlockedForScripts(): void
    {
        $result = $this->runner->run(new ScriptRunRequest(scripts: [new ScriptSource('pre', 'pm.environment.set("net", typeof fetch)')], limits: new ScriptRunLimits(timeMs: 15_000)));

        self::assertSame(ScriptRunStatus::Completed, $result->status, (string) $result->error);
        self::assertSame('undefined', $result->variables['net'], 'fetch is removed from the runner scope');
    }

    private static function nodeMajor(string $node): int
    {
        $process = new Process([$node, '-p', 'process.versions.node.split(".")[0]']);
        $process->run();

        return (int) trim($process->getOutput());
    }

    public function testPostmanStyleScriptsRunOutsideTheBrowser(): void
    {
        $post = <<<'JS'
            const json = pm.response.json();
            pm.test('three items', () => pm.expect(json.items).to.have.lengthOf(3));
            pm.test('fails', () => pm.expect(pm.response.code).to.equal(500));
            pm.test('status', () => pm.response.to.have.status(200));
            pm.test('header', () => pm.response.to.have.header('content-type', 'application/json'));
            pm.test('time', () => pm.expect(pm.response.responseTime).to.equal(12));
            pm.globals.set('seen', json.items.length);
            pm.console.log(typeof process, typeof Deno, typeof require, pm.variables.get('host'));
            pm.sendRequest('https://93.184.215.14/more', (err, res) => pm.environment.set('more', res.json().items.length));
            pm.environment.set('sum', require('lodash').sum(json.items));
            JS;
        $result = $this->runner->run(new ScriptRunRequest(
            scripts: [new ScriptSource('post-request', $post)],
            variables: ['token' => '{{token}}'],
            responseCode: 200,
            responseBody: '{"items":[1,2,3]}',
            globals: ['host' => 'example.org'],
            runTests: true,
            limits: new ScriptRunLimits(timeMs: 15_000),
            responseHeaders: ['Content-Type' => 'application/json'],
            responseTimeMs: 12,
        ));

        self::assertSame(ScriptRunStatus::Completed, $result->status, (string) $result->error);
        self::assertSame(['[post-request] undefined undefined function example.org'], $result->logs);
        self::assertEquals([new ScriptTestResult('three items', true), new ScriptTestResult('fails', false, 'Expected 500 but got 200'), new ScriptTestResult('status', true), new ScriptTestResult('header', true), new ScriptTestResult('time', true)], $result->tests);
        self::assertSame(['seen' => '3'], $result->globalChanges);
        self::assertSame('3', $result->variables['more']);
        self::assertSame('6', $result->variables['sum']);
        self::assertSame('{{token}}', $result->variables['token']);
    }

    public function testARunawayScriptIsKilled(): void
    {
        $result = $this->runner->run(new ScriptRunRequest(scripts: [new ScriptSource('pre', 'while (true) {}')], limits: new ScriptRunLimits(timeMs: 500)));

        self::assertSame(ScriptRunStatus::TimedOut, $result->status);
    }

    public function testAThrowingScriptFailsWithItsLabel(): void
    {
        $result = $this->runner->run(new ScriptRunRequest(scripts: [new ScriptSource('folder A pre', 'pm.console.log(1); null.x')], limits: new ScriptRunLimits(timeMs: 15_000)));

        self::assertSame(ScriptRunStatus::Failed, $result->status);
        self::assertSame('folder A pre', $result->failedScript);
        self::assertSame(['[folder A pre] 1'], $result->logs);
    }
}
