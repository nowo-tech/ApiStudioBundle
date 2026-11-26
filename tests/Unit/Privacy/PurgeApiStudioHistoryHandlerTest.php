<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Privacy;

use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryPurgeResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistory;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistoryHandler;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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

    public function testItIsAnAttributeHandler(): void
    {
        self::assertCount(1, (new ReflectionClass(PurgeApiStudioHistoryHandler::class))->getAttributes(AsMessageHandler::class));
    }
}
