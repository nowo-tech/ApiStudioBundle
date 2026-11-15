<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Secret;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ApiStudioSecretRefTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{0: string, 1: string}|null}>
     */
    public static function references(): iterable
    {
        yield 'plain' => ['{{secret:vault/payments}}', ['vault', 'payments']];
        yield 'spaces and path id' => ['  {{ secret:vault/team/payments:v2 }} ', ['vault', 'team/payments:v2']];
        yield 'uuid id' => ['{{secret:aws-sm/0b9c5b8e-1f1e-4a3c-9d7a-0c2a1b3c4d5e}}', ['aws-sm', '0b9c5b8e-1f1e-4a3c-9d7a-0c2a1b3c4d5e']];
        yield 'not a reference' => ['some-token-value', null];
        yield 'variable placeholder' => ['{{apiToken}}', null];
        yield 'no id' => ['{{secret:vault/}}', null];
        yield 'no source' => ['{{secret:/payments}}', null];
        yield 'upper-case source' => ['{{secret:Vault/payments}}', null];
        yield 'embedded in text' => ['Bearer {{secret:vault/payments}}', null];
        yield 'two references' => ['{{secret:vault/a}}{{secret:vault/b}}', null];
        yield 'space in id' => ['{{secret:vault/pay ments}}', null];
        yield 'overlong id' => ['{{secret:vault/' . str_repeat('a', 256) . '}}', null];
    }

    /**
     * @param array{0: string, 1: string}|null $expected
     */
    #[DataProvider('references')]
    public function testParse(string $value, ?array $expected): void
    {
        self::assertSame($expected, ApiStudioSecretRef::parse($value));
    }

    public function testCarriesTheContext(): void
    {
        $ref = new ApiStudioSecretRef('vault', 'payments', 'apiToken', 7, 3, 'org', 'acme', 'u:alice', 'https://api.example.test');

        self::assertSame(['vault', 'payments', 'apiToken', 7, 3, 'org', 'acme', 'u:alice', 'https://api.example.test'], [$ref->source, $ref->id, $ref->variableKey, $ref->workspaceId, $ref->environmentId, $ref->tenantType, $ref->tenantId, $ref->actorId, $ref->targetOrigin]);
    }

    public function testRejectsAnInvalidSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiStudioSecretRef('Vault!', 'payments', 'apiToken', 1, null, '', '', null, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'space' => ['pay ments'];
        yield 'parent segment' => ['../other-team/key'];
        yield 'inner parent segment' => ['team/../other/key'];
        yield 'trailing parent segment' => ['team/..'];
        yield 'dot segment' => ['team/./key'];
        yield 'leading slash' => ['/team/key'];
        yield 'empty segment' => ['team//key'];
        yield 'trailing slash' => ['team/key/'];
        yield 'control character' => ["team/key\n"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsAnInvalidId(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiStudioSecretRef('vault', $id, 'apiToken', 1, null, '', '', null, null);
    }

    public function testAcceptsDotsInsideASegment(): void
    {
        self::assertSame('team/key.v2/..hidden', (new ApiStudioSecretRef('vault', 'team/key.v2/..hidden', 'k', 1, null, '', '', null, null))->id);
    }
}
