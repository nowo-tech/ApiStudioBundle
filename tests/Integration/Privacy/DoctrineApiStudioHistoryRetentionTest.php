<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use LogicException;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioHistoryRetention;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\ApiStudioBundle\Tests\Support\PrivacyOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * 1.24 history retention on a real ORM schema (SQLite): rows executed before now − retention_days go, oldest first,
 * in batches; `maxBatches` bounds a run; 0 days disables it; a run inside an open transaction is refused; repeated
 * runs are idempotent.
 */
final class DoctrineApiStudioHistoryRetentionTest extends TestCase
{
    use PrivacyOrmTrait;

    private const NOW = '2026-11-26 12:00:00';

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    public function testPurgeDeletesOnlyRowsOlderThanTheRetentionInBatches(): void
    {
        $registry     = $this->privacyRegistry(null, false);
        $em           = $this->privacyEntityManager($registry);
        [, $endpoint] = $this->seedEndpoint($em);
        for ($day = 1; $day <= 5; ++$day) {
            $this->addHistory($em, $endpoint, null, executedAt: sprintf('2026-08-%02d 10:00:00', $day));
        }
        $edge      = $this->addHistory($em, $endpoint, null, executedAt: '2026-08-28 12:00:00'); // exactly 90 days: kept
        $recent    = $this->addHistory($em, $endpoint, null, executedAt: '2026-11-25 10:00:00');
        $retention = $this->retention($registry, 90, 2);

        self::assertSame(5, $retention->expiredRows($this->now()));
        self::assertEquals(new DateTimeImmutable('2026-08-28 12:00:00'), $retention->cutoff($this->now()));

        $first = $retention->purge(2, $this->now());
        self::assertSame(4, $first->deleted);
        self::assertSame(2, $first->batches);
        self::assertFalse($first->complete, 'stopped by maxBatches');
        self::assertEquals(new DateTimeImmutable('2026-08-28 12:00:00'), $first->cutoff);
        // Oldest first: the fifth day is what is left.
        self::assertSame('2026-08-05 10:00:00', $em->getConnection()->fetchOne('SELECT MIN(executed_at) FROM api_studio_request_history'));

        $second = $retention->purge(null, $this->now());
        self::assertSame(1, $second->deleted);
        self::assertSame(1, $second->batches);
        self::assertTrue($second->complete);

        $third = $retention->purge(null, $this->now());
        self::assertSame([0, 0, true], [$third->deleted, $third->batches, $third->complete]);

        $connection = $em->getConnection();
        self::assertSame(2, $this->countRows($connection, 'request_history'));
        self::assertSame(1, $this->countRows($connection, 'request_history', 'id = ' . $edge));
        self::assertSame(1, $this->countRows($connection, 'request_history', 'id = ' . $recent));
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertStringContainsString('history purge finished', $this->logger->text());
    }

    public function testAFullBatchIsFollowedByAnEmptyOne(): void
    {
        $registry     = $this->privacyRegistry(null, false);
        $em           = $this->privacyEntityManager($registry);
        [, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, null, executedAt: '2026-01-01 00:00:00');
        $this->addHistory($em, $endpoint, null, executedAt: '2026-01-02 00:00:00');

        $result = $this->retention($registry, 30, 2)->purge(null, $this->now());

        self::assertSame([2, 1, true], [$result->deleted, $result->batches, $result->complete]);
    }

    public function testZeroDaysKeepsEverything(): void
    {
        $registry     = $this->privacyRegistry(null, false);
        $em           = $this->privacyEntityManager($registry);
        [, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, null, executedAt: '2001-01-01 00:00:00');
        $retention = $this->retention($registry, 0, 10);

        $result = $retention->purge(null, $this->now());

        self::assertSame([0, 0, true, null], [$result->deleted, $result->batches, $result->complete, $result->cutoff]);
        self::assertNull($retention->cutoff());
        self::assertSame(0, $retention->expiredRows());
        self::assertSame(1, $this->countRows($em->getConnection(), 'request_history'));
    }

    public function testRefusedInsideAnOpenTransaction(): void
    {
        $registry   = $this->privacyRegistry(null, false);
        $connection = $this->privacyEntityManager($registry)->getConnection();
        $connection->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('outside a transaction');
            $this->retention($registry, 90, 10)->purge();
        } finally {
            $connection->rollBack();
        }
    }

    public function testDefaultsPurgeAgainstTheCurrentTime(): void
    {
        $registry     = $this->privacyRegistry(null, false);
        $em           = $this->privacyEntityManager($registry);
        [, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, null, executedAt: '2001-01-01 00:00:00');
        $this->addHistory($em, $endpoint, null);
        $retention = new DoctrineApiStudioHistoryRetention($this->privacyResolver($registry));

        self::assertSame(1, $retention->expiredRows());
        self::assertSame(1, $retention->purge()->deleted);
        self::assertSame(1, $this->countRows($em->getConnection(), 'request_history'));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function retention(ScopeManagerRegistry $registry, int $days, int $batchSize): DoctrineApiStudioHistoryRetention
    {
        return new DoctrineApiStudioHistoryRetention($this->privacyResolver($registry), $days, $batchSize, $this->logger);
    }
}
