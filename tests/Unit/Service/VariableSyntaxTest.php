<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use PHPUnit\Framework\TestCase;

final class VariableSyntaxTest extends TestCase
{
    public function testFormatKeyWrapsName(): void
    {
        self::assertSame('{{base_url}}', VariableSyntax::formatKey('base_url'));
    }

    public function testNormalizeKeyFromPlaceholder(): void
    {
        self::assertSame('api_key', VariableSyntax::normalizeKey('{{ api_key }}'));
    }

    public function testNormalizeKeyFromPlainName(): void
    {
        self::assertSame('token', VariableSyntax::normalizeKey('token'));
    }

    public function testNormalizeKeyRejectsInvalidSyntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VariableSyntax::normalizeKey('not valid!');
    }

    public function testIsPlaceholder(): void
    {
        self::assertTrue(VariableSyntax::isPlaceholder('{{base_url}}'));
        self::assertFalse(VariableSyntax::isPlaceholder('base_url'));
    }

    public function testFormatKeyRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VariableSyntax::formatKey('');
    }
}
