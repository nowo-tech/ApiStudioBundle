<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Nowo\ApiStudioBundle\Service\ImportExport\SlugHelper;
use PHPUnit\Framework\TestCase;

final class SlugHelperTest extends TestCase
{
    public function testFromStringNormalizesValue(): void
    {
        self::assertSame('json_placeholder', SlugHelper::fromString('JSON Placeholder'));
        self::assertSame('item', SlugHelper::fromString('!!!'));
    }

    public function testUniqueAppendsSuffixWhenSlugExists(): void
    {
        self::assertSame('payments', SlugHelper::unique('Payments', []));
        self::assertSame('payments_2', SlugHelper::unique('Payments', ['payments']));
        self::assertSame('payments_3', SlugHelper::unique('Payments', ['payments', 'payments_2']));
    }
}
