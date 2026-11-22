<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database\Support;

use RuntimeException;

use function fgets;
use function fwrite;
use function is_resource;
use function proc_close;
use function proc_open;
use function rtrim;
use function stream_set_timeout;

use const PHP_BINARY;

/**
 * A second database session in its own PHP process (second-connection.php), so a test can make it hold locks and
 * block while the test's own connection runs (copied from transaction-doctrine's real-database suite).
 */
final class SecondConnection
{
    /** A blocked statement answers within innodb_lock_wait_timeout (50 s by default). */
    private const READ_TIMEOUT_SECONDS = 90;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public function __construct(string $url)
    {
        // Test-only helper process; every read below is bounded by stream_set_timeout().
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $process = proc_open([PHP_BINARY, __DIR__ . '/second-connection.php', $url], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the second database session.');
        }

        $this->process = $process;
        $this->pipes   = $pipes;
        stream_set_timeout($this->pipes[1], self::READ_TIMEOUT_SECONDS);

        if ($this->readLine() !== 'READY') {
            throw new RuntimeException('The second database session did not start.');
        }
    }

    /**
     * Runs a statement and waits for its answer ("OK" or "ERR <code> <SQLSTATE>").
     */
    public function execute(string $sql): string
    {
        $this->send($sql);

        return $this->await();
    }

    /**
     * Sends a statement without waiting (it may block on a lock); read its answer later with await().
     */
    public function send(string $sql): void
    {
        fwrite($this->pipes[0], 'SQL ' . $sql . "\n");
    }

    /**
     * Queues a pause in the second session without waiting (answers "OK" afterwards; read it with await()).
     */
    public function sleep(int $milliseconds): void
    {
        fwrite($this->pipes[0], 'SLEEP ' . $milliseconds . "\n");
    }

    /** @phpstan-impure */
    public function await(): string
    {
        return $this->readLine();
    }

    public function close(): void
    {
        if (!isset($this->pipes[0])) {
            return;
        }

        fwrite($this->pipes[0], "EXIT\n");
        proc_close($this->process);
        $this->pipes = [];
    }

    private function readLine(): string
    {
        $line = fgets($this->pipes[1]);

        if ($line === false) {
            throw new RuntimeException('The second database session ended unexpectedly.');
        }

        return rtrim($line, "\r\n");
    }
}
