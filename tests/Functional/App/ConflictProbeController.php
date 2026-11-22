<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Query;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host routes of {@see TransactionDoctrineTestKernel} that raise the failures a conflict responder has to tell apart:
 * a domain stale edit (bare and wrapped) and a real deadlock. Nothing is written.
 */
final class ConflictProbeController
{
    public function stale(): Response
    {
        throw new StaleEditException();
    }

    /** The same, two wrappers deep (a save that rethrows, a host decorator). */
    public function wrapped(): Response
    {
        throw new RuntimeException('outer', 0, new RuntimeException('middle', 0, new StaleEditException()));
    }

    /** A genuine transaction-level conflict: InnoDB 1213 / SQLSTATE 40001. */
    public function deadlock(): Response
    {
        $pdo            = new PDOException('Deadlock found when trying to get lock', 40001);
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        throw new DeadlockException(PdoDriverException::new($pdo), new Query('UPDATE x SET y = 1', [], []));
    }
}
