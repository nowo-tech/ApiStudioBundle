<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Secret;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretValue;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use WeakMap;

use function count;
use function gc_collect_cycles;
use function json_encode;
use function print_r;
use function serialize;
use function sprintf;
use function strlen;
use function unserialize;
use function var_export;

final class ApiStudioSecretValueTest extends TestCase
{
    public function testRevealsOnlyThroughReveal(): void
    {
        $marker = self::marker();
        $value  = new ApiStudioSecretValue($marker);

        self::assertSame($marker, $value->reveal());
        self::assertSame(['value' => '***'], $value->__debugInfo());
    }

    public function testNoDumpCastOrEncodingShowsTheValue(): void
    {
        // QE-B-L3: VarDumper (dump(), the profiler) reads the property table, not __debugInfo.
        $marker = self::marker();
        $value  = new ApiStudioSecretValue($marker);

        $dumped = (string) (new CliDumper())->dump((new VarCloner())->cloneVar($value), true);
        self::assertStringNotContainsString($marker, $dumped);
        self::assertStringNotContainsString($marker, var_export($value, true));
        self::assertStringNotContainsString($marker, print_r((array) $value, true));
        self::assertStringNotContainsString($marker, print_r($value, true));
        self::assertStringNotContainsString($marker, (string) json_encode($value));
    }

    public function testEachObjectKeepsItsOwnValue(): void
    {
        $first  = new ApiStudioSecretValue(self::marker() . '-one');
        $second = new ApiStudioSecretValue(self::marker() . '-two');

        self::assertSame(self::marker() . '-one', $first->reveal());
        self::assertSame(self::marker() . '-two', $second->reveal());
    }

    public function testNothingOutlivesTheValueObjects(): void
    {
        // Worker mode: the weak storage holds no entry once the per-execution objects are gone.
        $values = [new ApiStudioSecretValue(self::marker() . '-a'), new ApiStudioSecretValue(self::marker() . '-b')];
        $store  = (new ReflectionProperty(ApiStudioSecretValue::class, 'values'))->getValue();
        self::assertInstanceOf(WeakMap::class, $store);
        $before = count($store);

        unset($values);
        gc_collect_cycles();

        self::assertCount($before - 2, $store);
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);
        serialize(new ApiStudioSecretValue(self::marker()));
    }

    public function testCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen(ApiStudioSecretValue::class), ApiStudioSecretValue::class));
    }

    public function testCannotBeCloned(): void
    {
        $value = new ApiStudioSecretValue(self::marker());

        $this->expectException(LogicException::class);
        $copy = clone $value;
        self::fail($copy->reveal());
    }

    public function testRejectsAnEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiStudioSecretValue('');
    }

    private static function marker(): string
    {
        return 'hv-REDACTED-TEST';
    }
}
