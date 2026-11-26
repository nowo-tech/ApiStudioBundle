<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Privacy;

use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryPurgeResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use Nowo\ApiStudioBundle\Privacy\HistoryPurgeInsideTransactionException;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistory;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistoryHandler;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * 1.24: the Messenger handler runs the retention with the message's bound; a conflict reaches Messenger's retry.
 */
final class PurgeApiStudioHistoryHandlerTest extends TestCase
{
    public function testItRunsTheRetention(): void
    {
        $retention = $this->createMock(ApiStudioHistoryRetentionInterface::class);
        $retention->expects(self::once())->method('purge')->with(3)->willReturn(new ApiStudioHistoryPurgeResult(10, 3, false, null));

        (new PurgeApiStudioHistoryHandler($retention))(new PurgeApiStudioHistory(3));
    }

    public function testAConflictPropagatesToMessenger(): void
    {
        $retention = $this->createStub(ApiStudioHistoryRetentionInterface::class);
        $retention->method('purge')->willThrowException(new TransientStorageFailure(1));

        $this->expectException(TransientStorageFailure::class);
        (new PurgeApiStudioHistoryHandler($retention))(new PurgeApiStudioHistory());
    }

    /**
     * Audit ZCL-L2: behind the doctrine_transaction middleware a retry cannot help.
     */
    public function testInsideATransactionItFailsUnrecoverablyWithALog(): void
    {
        $retention = $this->createStub(ApiStudioHistoryRetentionInterface::class);
        $retention->method('purge')->willThrowException(new HistoryPurgeInsideTransactionException());
        $logger = new InMemoryLogger();

        try {
            (new PurgeApiStudioHistoryHandler($retention, $logger))(new PurgeApiStudioHistory());
            self::fail('Expected an unrecoverable failure.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertInstanceOf(HistoryPurgeInsideTransactionException::class, $e->getPrevious());
        }

        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('doctrine_transaction', $logger->records[0]['message']);
    }

    public function testItIsAnAttributeHandler(): void
    {
        self::assertCount(1, (new ReflectionClass(PurgeApiStudioHistoryHandler::class))->getAttributes(AsMessageHandler::class));
    }
}
