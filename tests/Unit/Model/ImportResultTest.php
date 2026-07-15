<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Model;

use Nowo\ApiStudioBundle\Model\ImportResult;
use PHPUnit\Framework\TestCase;

final class ImportResultTest extends TestCase
{
    public function testMergeAggregatesCountsAndMessages(): void
    {
        $left   = new ImportResult(servicesCreated: 1, endpointsCreated: 2, messages: ['a']);
        $right  = new ImportResult(servicesCreated: 3, variablesCreated: 4, messages: ['b']);
        $merged = $left->merge($right);

        self::assertSame(4, $merged->servicesCreated);
        self::assertSame(2, $merged->endpointsCreated);
        self::assertSame(4, $merged->variablesCreated);
        self::assertSame(['a', 'b'], $merged->messages);
    }
}
