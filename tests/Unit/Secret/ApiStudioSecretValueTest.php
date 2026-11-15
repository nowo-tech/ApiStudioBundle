<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Secret;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretValue;
use PHPUnit\Framework\TestCase;

use function print_r;
use function serialize;
use function var_export;

final class ApiStudioSecretValueTest extends TestCase
{
    public function testRevealsOnlyThroughReveal(): void
    {
        $marker = 'hv-REDACTED-TEST';
        $value  = new ApiStudioSecretValue($marker);

        self::assertSame($marker, $value->reveal());
        self::assertSame(['value' => '***'], $value->__debugInfo());
        self::assertStringNotContainsString($marker, print_r($value, true));
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize(new ApiStudioSecretValue('hv-REDACTED-TEST'));
    }

    public function testRejectsAnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        var_export(new ApiStudioSecretValue(''));
    }
}
