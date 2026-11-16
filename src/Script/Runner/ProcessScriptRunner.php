<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Script\ScriptRequestSender;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function bin2hex;
use function fclose;
use function fread;
use function fwrite;
use function getenv;
use function hrtime;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function sprintf;
use function str_replace;
use function stream_set_blocking;
use function strlen;
use function strpos;
use function substr;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Reference server runner (spec 003, 1.15): one sidecar process per run (Deno by default: no file system, network,
 * environment or subprocess permission), started with proc_open (no shell) and spoken to over non-blocking stdin /
 * stdout with the sandbox protocol, one JSON message per line:
 *
 * - runner ← `{v:1, kind:'run', nonce, scripts, state, runTests, sendBudget}`;
 * - runner → `{v:1, kind:'sendRequest', nonce, id, request}` ← `{v:1, kind:'sendResult', nonce, id, response|error}`;
 * - runner → `{v:1, kind:'result', nonce, ok, error?, label?, state}`.
 *
 * Limits: wall time (the process is killed at the limit), output (stdout + stderr, killed past it), memory (handed to
 * the sidecar through the `{memory_mb}` placeholder of the command, e.g. the V8 heap limit) and the `pm.sendRequest`
 * budget (enforced here too). `pm.sendRequest` goes through {@see ScriptRequestSender}: literal, guarded, no secret
 * resolved. The process is always stopped before run() returns; nothing is kept between runs (worker mode).
 *
 * Output is read here in bounded chunks (never more than the output limit plus one chunk is held), so a sidecar that
 * floods stdout is killed at the limit instead of filling the PHP process memory.
 */
final class ProcessScriptRunner implements ApiStudioScriptRunnerInterface
{
    public const RUNNER_PLACEHOLDER = '{runner}';
    public const MEMORY_PLACEHOLDER = '{memory_mb}';

    /** Environment variables handed to the sidecar (everything else is removed). */
    private const KEPT_ENVIRONMENT = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'DENO_DIR'];

    /**
     * @param list<string> $command sidecar command; `{runner}` is replaced with $runnerPath, `{memory_mb}` with the limit
     */
    public function __construct(
        private readonly array $command,
        private readonly string $runnerPath,
        private readonly ?ScriptRequestSender $sender = null,
        private readonly int $pollMicroseconds = 1000,
    ) {
        if ($command === []) {
            throw new InvalidArgumentException('scripts.server_runner.command cannot be empty.');
        }
    }

    /** Most bytes read from one pipe per call. */
    private const CHUNK = 8192;

    public function run(ScriptRunRequest $request): ScriptRunResult
    {
        $nonce = bin2hex(random_bytes(16));
        $pipes = [];
        // The wall-time limit is enforced by converse(), which kills the sidecar at the deadline.
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $process = @proc_open($this->commandLine($request->limits), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment());
        if (!is_resource($process)) {
            return ScriptRunResult::unchanged($request, ScriptRunStatus::Failed, 'The script runner could not be started.');
        }

        try {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }

            return $this->converse($process, $pipes, $request, $nonce);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function converse($process, array $pipes, ScriptRunRequest $request, string $nonce): ScriptRunResult
    {
        $limits    = $request->limits;
        $deadline  = hrtime(true) + $limits->timeMs * 1_000_000;
        $maxOutput = $limits->outputKb * 1024;
        $output    = 0;
        $buffer    = '';
        $budget    = $limits->sendRequests;
        $pending   = $this->encode($this->runMessage($request, $nonce));

        while (true) {
            $status = proc_get_status($process);
            // stdin: what the sidecar has not taken yet (non-blocking, so a sidecar that never reads cannot stall us).
            if ($pending !== '') {
                $written = @fwrite($pipes[0], $pending);
                if ($written === false) {
                    $pending = '';
                } elseif ($written > 0) {
                    $pending = substr($pending, $written);
                }
            }
            // stderr is only counted; stdout is parsed. Never more than the remaining allowance plus one byte is read.
            foreach ([2, 1] as $fd) {
                while (($chunk = $this->readChunk($pipes[$fd], $maxOutput - $output + 1)) !== '') {
                    $output += strlen($chunk);
                    if ($output > $maxOutput) {
                        return ScriptRunResult::unchanged($request, ScriptRunStatus::OutputExceeded, sprintf('Scripts exceeded the %d KB output limit.', $limits->outputKb));
                    }
                    if ($fd === 1) {
                        $buffer .= $chunk;
                    }
                }
            }

            while (($end = strpos($buffer, "\n")) !== false) {
                $message = json_decode(substr($buffer, 0, $end), true);
                $buffer  = substr($buffer, $end + 1);
                // Anything that is not a message of this run (e.g. a script writing to the real console) is ignored.
                if (!is_array($message) || ($message['v'] ?? null) !== 1 || ($message['nonce'] ?? null) !== $nonce) {
                    continue;
                }
                if (($message['kind'] ?? null) === 'result') {
                    return $this->result($request, $message);
                }
                if (($message['kind'] ?? null) === 'sendRequest' && is_int($message['id'] ?? null)) {
                    $pending .= $this->encode($this->sendResult($message, $nonce, $budget));
                }
            }

            if (!$status['running']) {
                return ScriptRunResult::unchanged($request, ScriptRunStatus::Failed, sprintf('The script runner exited without a result (exit code %s).', $status['exitcode'] >= 0 ? $status['exitcode'] : '?'));
            }
            if (hrtime(true) > $deadline) {
                return ScriptRunResult::unchanged($request, ScriptRunStatus::TimedOut, sprintf('Scripts exceeded the %d ms time limit.', $limits->timeMs));
            }
            usleep($this->pollMicroseconds);
        }
    }

    /**
     * At most min(CHUNK, $max) bytes available on $pipe now ('' when none).
     *
     * @param resource $pipe
     */
    private function readChunk($pipe, int $max): string
    {
        $chunk = @fread($pipe, max(1, min(self::CHUNK, $max)));

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * @param array<mixed> $message
     *
     * @return array<string, mixed>
     */
    private function sendResult(array $message, string $nonce, int &$budget): array
    {
        $reply = ['v' => 1, 'kind' => 'sendResult', 'nonce' => $nonce, 'id' => $message['id']];
        if (!$this->sender instanceof ScriptRequestSender) {
            return $reply + ['error' => 'pm.sendRequest is not available in this runner.'];
        }
        if ($budget <= 0) {
            return $reply + ['error' => 'pm.sendRequest: request budget exhausted.'];
        }
        --$budget;
        $payload = $message['request'] ?? null;

        try {
            return $reply + ['response' => $this->sender->send(is_array($payload) ? $payload : [])];
        } catch (InvalidArgumentException $e) {
            // The sender reports refusals and transport failures this way (exception class only, no detail).
            return $reply + ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runMessage(ScriptRunRequest $request, string $nonce): array
    {
        return [
            'v'          => 1,
            'kind'       => 'run',
            'nonce'      => $nonce,
            'runTests'   => $request->runTests,
            'sendBudget' => $request->limits->sendRequests,
            'scripts'    => array_map(static fn (ScriptSource $s): array => ['label' => $s->label, 'code' => $s->code], $request->scripts),
            'state'      => [
                'variables' => (object) $request->variables,
                'dirty'     => (object) [],
                'request'   => ['body' => $request->body, 'headers' => (object) $request->headers, 'queryParams' => (object) $request->queryParams],
                'response'  => $request->responseCode === null && $request->responseBody === null ? null : [
                    'code'         => $request->responseCode,
                    'body'         => (string) $request->responseBody,
                    'headers'      => (object) $request->responseHeaders,
                    'responseTime' => $request->responseTimeMs,
                ],
                'logs'   => [],
                'tests'  => [],
                'scopes' => [
                    'globals'    => (object) $request->globals,
                    'collection' => (object) $request->collectionVariables,
                    'changes'    => ['globals' => (object) [], 'collection' => (object) []],
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $message
     */
    private function result(ScriptRunRequest $request, array $message): ScriptRunResult
    {
        $state   = $message['state'] ?? null;
        $req     = is_array($state) ? ($state['request'] ?? null) : null;
        $scopes  = is_array($state) ? ($state['scopes'] ?? null) : null;
        $changes = is_array($scopes) ? ($scopes['changes'] ?? null) : null;
        if (!is_bool($message['ok'] ?? null) || !is_array($state) || !is_array($req) || !is_array($scopes) || !is_array($changes)) {
            return ScriptRunResult::unchanged($request, ScriptRunStatus::Failed, 'The script runner answered an invalid result.');
        }

        $tests = [];
        foreach (is_array($state['tests'] ?? null) ? $state['tests'] : [] as $test) {
            if (is_array($test) && is_string($test['name'] ?? null) && is_bool($test['ok'] ?? null)) {
                $tests[] = new ScriptTestResult($test['name'], $test['ok'], is_string($test['error'] ?? null) ? $test['error'] : null);
            }
        }
        $ok = $message['ok'];

        return new ScriptRunResult(
            status: $ok ? ScriptRunStatus::Completed : ScriptRunStatus::Failed,
            error: $ok ? null : (is_string($message['error'] ?? null) ? $message['error'] : 'Script failed.'),
            failedScript: $ok || !is_string($message['label'] ?? null) ? null : $message['label'],
            variables: self::strings($state['variables'] ?? null),
            changedVariables: self::strings($state['dirty'] ?? null),
            body: is_string($req['body'] ?? null) ? $req['body'] : $request->body,
            headers: self::strings($req['headers'] ?? null),
            queryParams: self::strings($req['queryParams'] ?? null),
            logs: array_values(array_filter(is_array($state['logs'] ?? null) && array_is_list($state['logs']) ? $state['logs'] : [], is_string(...))),
            tests: $tests,
            globals: self::strings($scopes['globals'] ?? null),
            collectionVariables: self::strings($scopes['collection'] ?? null),
            globalChanges: self::changes($changes['globals'] ?? null),
            collectionChanges: self::changes($changes['collection'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $map): array
    {
        $strings = [];
        foreach (is_array($map) ? $map : [] as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string|null>
     */
    private static function changes(mixed $map): array
    {
        $changes = [];
        foreach (is_array($map) ? $map : [] as $key => $value) {
            if ($value === null || is_string($value)) {
                $changes[(string) $key] = $value;
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function encode(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @return list<string>
     */
    private function commandLine(ScriptRunLimits $limits): array
    {
        return array_map(
            fn (string $part): string => str_replace([self::RUNNER_PLACEHOLDER, self::MEMORY_PLACEHOLDER], [$this->runnerPath, (string) $limits->memoryMb], $part),
            $this->command,
        );
    }

    /**
     * The parent's environment minus everything but a few harmless variables (no secret of the host reaches the
     * sidecar through its environment).
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            if (in_array($name, self::KEPT_ENVIRONMENT, true)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
