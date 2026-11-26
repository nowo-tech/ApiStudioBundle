<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Privacy;

use Nowo\ApiStudioBundle\Privacy\ApiStudioErasureResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryPurgeResult;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExport;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistory;
use PHPUnit\Framework\TestCase;

/**
 * 1.24 GDPR / retention value objects.
 */
final class ValueObjectsTest extends TestCase
{
    public function testErasureResultsAddUp(): void
    {
        $sum = (new ApiStudioErasureResult(2, 1))->with(new ApiStudioErasureResult(3, 0));

        self::assertSame([5, 1], [$sum->historyRowsDeleted, $sum->foldersAnonymised]);
        self::assertSame([0, 0], [(new ApiStudioErasureResult())->historyRowsDeleted, (new ApiStudioErasureResult())->foldersAnonymised]);
    }

    public function testExportsMergeEachRowOnce(): void
    {
        $row    = ['id' => 1, 'endpoint_id' => 4, 'method' => 'GET', 'url' => 'https://api.example.test/', 'status' => 200, 'executed_at' => '2026-11-26T10:00:00+00:00'];
        $other  = ['id' => 2] + $row;
        $folder = ['id' => 9, 'name' => 'Invoices', 'service_id' => 3, 'created_at' => '2026-11-26T10:00:00+00:00'];

        $merged = (new ApiStudioPersonalDataExport([$row], [$folder]))->with(new ApiStudioPersonalDataExport([$row, $other], [$folder]));

        self::assertSame(['history' => [$row, $other], 'folders_created' => [$folder]], $merged->toArray());
        self::assertSame(['history' => [], 'folders_created' => []], (new ApiStudioPersonalDataExport())->toArray());
    }

    public function testDisabledPurgeResult(): void
    {
        $result = ApiStudioHistoryPurgeResult::disabled();

        self::assertSame([0, 0, true, null], [$result->deleted, $result->batches, $result->complete, $result->cutoff]);
    }

    public function testPurgeMessage(): void
    {
        self::assertNull((new PurgeApiStudioHistory())->maxBatches);
        self::assertSame(5, (new PurgeApiStudioHistory(5))->maxBatches);
    }
}
