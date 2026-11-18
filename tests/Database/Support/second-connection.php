<?php

declare(strict_types=1);

/*
 * Second database session for the real-database race tests (1.17, REQ-RUNTIME-003): it reads one command per line
 * from STDIN and answers one line per command on STDOUT, so a test can make it hold locks, wait and commit while the
 * test's own connection runs.
 *
 *   SQL <statement>   execute the statement; answers "OK" or "ERR <driver code> <SQLSTATE>"
 *   SLEEP <ms>        wait, then answer "OK" (queue it before a COMMIT to release a lock while the test is blocked)
 *   EXIT              close the connection and stop
 *
 * Usage: php second-connection.php <database url>
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$url = $argv[1] ?? '';

if ($url === '') {
    throw new InvalidArgumentException('Usage: php second-connection.php <database url>');
}

$parser     = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
$connection = DriverManager::getConnection($parser->parse($url));
$connection->fetchOne('SELECT 1');

fwrite(\STDOUT, "READY\n");

while (($line = fgets(\STDIN)) !== false) {
    $line = rtrim($line, "\r\n");

    if ($line === 'EXIT') {
        break;
    }

    if (str_starts_with($line, 'SLEEP ')) {
        usleep(1000 * (int) substr($line, 6));
        fwrite(\STDOUT, "OK\n");
        continue;
    }

    if (!str_starts_with($line, 'SQL ')) {
        fwrite(\STDOUT, "ERR 0 unknown-command\n");
        continue;
    }

    try {
        $connection->executeStatement(substr($line, 4));
        fwrite(\STDOUT, "OK\n");
    } catch (DriverException $exception) {
        fwrite(\STDOUT, sprintf("ERR %d %s\n", $exception->getCode(), $exception->getSQLState() ?? '-'));
    }
}

$connection->close();
