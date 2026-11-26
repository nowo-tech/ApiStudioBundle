<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\ApiStudioBundle\Privacy\BatchWriter;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function extension_loaded;

/**
 * 1.24: one batch = one write unit; a lock conflict is retried only outside a caller's transaction, a bounded number
 * of times; the connection is back at its entry level after every outcome.
 */
final class BatchWriterTest extends TestCase
{
    private Connection $connection;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->logger     = new InMemoryLogger();
    }

    public function testAConflictOutsideATransactionIsRetried(): void
    {
        $calls = 0;
        $rows  = (new BatchWriter($this->connection, $this->logger, 3))->write(function () use (&$calls): int {
            ++$calls;
            self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'each attempt in its own transaction');
            if ($calls === 1) {
                throw new TransientStorageFailure(1);
            }

            return 7;
        });

        self::assertSame(7, $rows);
        self::assertSame(2, $calls);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame([['attempt' => 1, 'exception' => TransientStorageFailure::class]], $this->retries());
    }

    public function testTheConflictPropagatesAfterTheLastAttempt(): void
    {
        $calls = 0;

        try {
            (new BatchWriter($this->connection, null, 3))->write(static function () use (&$calls): int {
                ++$calls;

                throw new TransientStorageFailure(1);
            });
            self::fail('Expected the conflict.');
        } catch (TransientStorageFailure) {
        }

        self::assertSame(3, $calls);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    public function testInsideACallersTransactionTheConflictPropagatesAtOnce(): void
    {
        $this->connection->beginTransaction();
        $calls = 0;

        try {
            (new BatchWriter($this->connection, $this->logger))->write(static function () use (&$calls): int {
                ++$calls;

                throw new TransientStorageFailure(1);
            });
            self::fail('Expected the conflict.');
        } catch (TransientStorageFailure) {
        }

        self::assertSame(1, $calls);
        self::assertSame(1, $this->connection->getTransactionNestingLevel(), 'repaired back to the caller\'s level');
        self::assertSame([], $this->retries());
        $this->connection->rollBack();
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $calls = 0;

        try {
            (new BatchWriter($this->connection))->write(static function () use (&$calls): int {
                ++$calls;

                throw new RuntimeException('not a lock conflict');
            });
            self::fail('Expected the failure.');
        } catch (RuntimeException $e) {
            self::assertSame('not a lock conflict', $e->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * Context of the retry notices (the runner logs its own records to the same logger).
     *
     * @return list<array<array-key, mixed>>
     */
    private function retries(): array
    {
        $retries = [];
        foreach ($this->logger->records as $record) {
            if ($record['message'] === 'Api Studio: batch write conflict, retrying.') {
                $retries[] = $record['context'];
            }
        }

        return $retries;
    }
}
