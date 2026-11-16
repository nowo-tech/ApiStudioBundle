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

use function dirname;
use function is_file;

/**
 * Spec 003 (1.15): the bundled sidecar (src/Resources/runner/api-script-runner.js) under Node, as a host would run it
 * (Deno is the documented default; Node is what CI has). Skipped without node.
 */
final class BundledScriptRunnerTest extends TestCase
{
    private const RUNNER = __DIR__ . '/../../src/Resources/runner/api-script-runner.js';

    private ProcessScriptRunner $runner;

    protected function setUp(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null || !is_file(self::RUNNER)) {
            self::markTestSkipped('node or the built runner is not available.');
        }
        $sender       = new ScriptRequestSender(new MockHttpClient(new MockResponse('{"items":[1,2,3]}', ['http_code' => 200])), new ExecutionUrlValidator([]));
        $this->runner = new ProcessScriptRunner([$node, '--max-old-space-size={memory_mb}', '{runner}'], dirname(self::RUNNER) . '/api-script-runner.js', $sender);
    }

    public function testPostmanStyleScriptsRunOutsideTheBrowser(): void
    {
        $post = <<<'JS'
            const json = pm.response.json();
            pm.test('three items', () => pm.expect(json.items).to.have.lengthOf(3));
            pm.test('fails', () => pm.expect(pm.response.code).to.equal(500));
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
        ));

        self::assertSame(ScriptRunStatus::Completed, $result->status, (string) $result->error);
        self::assertSame(['[post-request] undefined undefined function example.org'], $result->logs);
        self::assertEquals([new ScriptTestResult('three items', true), new ScriptTestResult('fails', false, 'Expected 500 but got 200')], $result->tests);
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
