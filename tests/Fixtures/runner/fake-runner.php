<?php

declare(strict_types=1);

/*
 * Fake script runner sidecar of the ProcessScriptRunner tests (spec 003, 1.15): speaks the line protocol like the
 * bundled runner. argv: mode, memory limit, runner path.
 */

$mode  = $argv[1] ?? 'echo';
$line  = fgets(\STDIN);
$run   = is_string($line) ? json_decode($line, true) : null;
$nonce = is_array($run) ? (string) ($run['nonce'] ?? '') : '';
$state = is_array($run) ? $run['state'] : [];
$out   = static function (array $message): void {
    fwrite(\STDOUT, json_encode($message) . "\n");
    fflush(\STDOUT);
};
$result = static function (bool $ok, array $state, ?string $error = null) use ($out, $nonce): void {
    $out(['v' => 1, 'kind' => 'result', 'nonce' => $nonce, 'ok' => $ok] + ($error !== null ? ['error' => $error, 'label' => 'pre'] : []) + ['state' => $state]);
};

switch ($mode) {
    case 'echo':
        fwrite(\STDOUT, "not json\n");
        $out(['v' => 1, 'kind' => 'result', 'nonce' => 'other', 'ok' => false, 'state' => []]);
        fwrite(\STDERR, 'a warning');
        $state['variables']['seen']           = implode('|', [count($run['scripts']), $run['scripts'][0]['label'] ?? '', $argv[2] ?? '', basename($argv[3] ?? ''), getenv('API_STUDIO_RUNNER_TEST_SECRET') === false ? 'no-secret' : 'secret', (string) $run['sendBudget']]);
        $state['dirty']                       = ['seen' => $state['variables']['seen']];
        $state['request']['headers']['X-Run'] = '1';
        $state['logs']                        = ['[pre] hello', 7];
        $state['tests']                       = [['name' => 'ok', 'ok' => true], ['name' => 'bad', 'ok' => false, 'error' => 'boom'], 'junk'];
        $state['scopes']['globals']['g']      = 'G';
        $state['scopes']['changes']           = ['globals' => ['g' => 'G', 'gone' => null, 'n' => 1], 'collection' => []];
        $result(true, $state);
        break;
    case 'fail':
        $result(false, $state, 'TypeError: x is not a function');
        break;
    case 'send':
        $answers = [];
        foreach ([1, 2, 3] as $id) {
            $out(['v' => 1, 'kind' => 'sendRequest', 'nonce' => $nonce, 'id' => $id, 'request' => ['url' => $id === 2 ? 'http://127.0.0.1/' : 'https://93.184.215.14/x', 'method' => 'GET', 'header' => [], 'body' => null]]);
            $reply     = json_decode((string) fgets(\STDIN), true);
            $answers[] = isset($reply['response']) ? 'code ' . $reply['response']['code'] : 'error ' . $reply['error'];
        }
        $state['logs'] = $answers;
        $result(true, $state);
        break;
    case 'sleep':
        sleep(30);
        break;
    case 'flood':
        for ($i = 0; $i < 1_000_000; ++$i) {
            fwrite(\STDOUT, str_repeat('x', 8192) . "\n");
        }
        break;
    case 'exit':
        throw new RuntimeException('runner crashed');
    case 'invalid':
        $out(['v' => 1, 'kind' => 'result', 'nonce' => $nonce, 'ok' => 'yes', 'state' => $state]);
        break;
}
