<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\ApiStudioBundle\Service\FolderTreeConflictException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 1.14 audit RJ-D2-L1: a retryable error hidden behind a failed savepoint rollback still answers 409.
 */
final class FolderTreeConflictExceptionTest extends TestCase
{
    public function testDeadlocksStayAsTheyAre(): void
    {
        $deadlock = new DeadlockException(self::driver('Deadlock found', 1213), null);

        self::assertSame($deadlock, FolderTreeConflictException::wrap($deadlock));
    }

    public function testALostSavepointIsARetryableConflict(): void
    {
        $lost    = new DriverException(self::driver('SAVEPOINT DOCTRINE_2 does not exist', 1305), null);
        $wrapped = FolderTreeConflictException::wrap($lost);

        self::assertInstanceOf(RetryableException::class, $wrapped);
        self::assertSame($lost, $wrapped->getPrevious());
    }

    public function testARetryableCauseDeeperInTheChainIsFound(): void
    {
        $deadlock = new DeadlockException(self::driver('Deadlock found', 1213), null);
        $outer    = new RuntimeException('flush failed', 0, $deadlock);

        self::assertInstanceOf(FolderTreeConflictException::class, FolderTreeConflictException::wrap($outer));
    }

    public function testError1305WithoutASavepointIsNotAConflict(): void
    {
        $missingFunction = new DriverException(self::driver('FUNCTION app.nope does not exist', 1305), null);

        self::assertSame($missingFunction, FolderTreeConflictException::wrap($missingFunction));
    }

    public function testOtherErrorsAreUnchanged(): void
    {
        $other = new DriverException(self::driver('Syntax error', 1064), null);

        self::assertSame($other, FolderTreeConflictException::wrap($other));
    }

    private static function driver(string $message, int $code): AbstractException
    {
        return new class($message, 'HY000', $code) extends AbstractException {
        };
    }
}
