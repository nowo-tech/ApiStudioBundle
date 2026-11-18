<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Seed;

use Closure;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Seed\EndpointSeed;
use Nowo\ApiStudioBundle\Seed\EnvironmentSeed;
use Nowo\ApiStudioBundle\Seed\SecretReferenceSeed;
use Nowo\ApiStudioBundle\Seed\SeedStats;
use Nowo\ApiStudioBundle\Seed\ServiceSeed;
use Nowo\ApiStudioBundle\Seed\VariableSeed;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 1.17 seed value objects: validated at construction, messages name the field and never carry a value.
 */
final class SeedValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function invalidSeeds(): iterable
    {
        yield 'workspace slug' => [static fn () => new WorkspaceSeed('Not A Slug', 'W'), 'workspace slug must be a slug'];
        yield 'long slug' => [static fn () => new WorkspaceSeed(str_repeat('a', 129), 'W'), 'workspace slug must be a slug'];
        yield 'blank name' => [static fn () => new WorkspaceSeed('w', '  '), 'workspace name must be 1 to 128'];
        yield 'long name' => [static fn () => new WorkspaceSeed('w', str_repeat('n', 129)), 'workspace name must be 1 to 128'];
        yield 'duplicate service' => [static fn () => new WorkspaceSeed('w', 'W', services: [new ServiceSeed('s', 'S'), new ServiceSeed('s', 'T')]), 'duplicate service slug "s"'];
        yield 'duplicate environment' => [static fn () => new WorkspaceSeed('w', 'W', environments: [new EnvironmentSeed('e', 'E'), new EnvironmentSeed('e', 'F')]), 'duplicate environment slug'];
        yield 'two defaults' => [static fn () => new WorkspaceSeed('w', 'W', environments: [new EnvironmentSeed('e', 'E', true), new EnvironmentSeed('f', 'F', true)]), 'at most one environment'];
        yield 'service base url' => [static fn () => new ServiceSeed('s', 'S', ''), 'base URL must be 1 to 512'];
        yield 'long base url' => [static fn () => new ServiceSeed('s', 'S', str_repeat('u', 513)), 'base URL must be 1 to 512'];
        yield 'duplicate endpoint' => [static fn () => new ServiceSeed('s', 'S', endpoints: [new EndpointSeed('e', 'E'), new EndpointSeed('e', 'F')]), 'duplicate endpoint slug'];
        yield 'endpoint path' => [static fn () => new EndpointSeed('e', 'E', path: ''), 'endpoint path must be 1 to 512'];
        yield 'endpoint content type' => [static fn () => new EndpointSeed('e', 'E', contentType: ''), 'endpoint content type'];
        yield 'variable key' => [static fn () => new VariableSeed('bad key', 'v'), 'variable key must match'];
        yield 'reference as a variable' => [static fn () => new VariableSeed('k', '{{ secret:vault/x }}'), 'must be seeded as a SecretReferenceSeed'];
        yield 'reference source' => [static fn () => new SecretReferenceSeed('k', 'Vault', 'x'), 'a secret reference needs a source'];
        yield 'reference traversal' => [static fn () => new SecretReferenceSeed('k', 'vault', '../x'), 'a secret reference needs a source'];
        yield 'reference key' => [static fn () => new SecretReferenceSeed('bad key', 'vault', 'x'), 'secret reference key'];
        yield 'same key twice' => [static fn () => new EnvironmentSeed('e', 'E', false, [new VariableSeed('k', 'v')], [new SecretReferenceSeed('k', 'vault', 'x')]), 'duplicate variable key "k"'];
    }

    /**
     * @param Closure(): mixed $build
     */
    #[DataProvider('invalidSeeds')]
    public function testInvalidSeedsAreRefused(Closure $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testAValidSeed(): void
    {
        $reference = new SecretReferenceSeed('api_key', 'vault', 'core/mapfre/api-key', 'Key');
        self::assertSame('{{secret:vault/core/mapfre/api-key}}', $reference->reference());

        $seed = new WorkspaceSeed('w', 'W', environments: [new EnvironmentSeed('e', 'E', secretReferences: [$reference])]);
        self::assertTrue($seed->hasSecretReferences());
        self::assertTrue($seed->tenant->isInstance());
        self::assertNull($seed->scope);
        self::assertFalse((new WorkspaceSeed('w', 'W', environments: [new EnvironmentSeed('e', 'E')]))->hasSecretReferences());
    }

    public function testTheValueOfARefusedVariableIsNotInTheMessage(): void
    {
        try {
            new VariableSeed('k', '{{secret:vault/very-private-id}}');
            self::fail('Expected a refusal.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('very-private-id', $e->getMessage());
        }
    }

    public function testStatsCountCreatedAndUpdatedRows(): void
    {
        $stats = new SeedStats();
        $stats->count('service', false);
        $stats->count('service', false);
        $stats->count('service', true);

        self::assertSame(['service' => 2], $stats->created);
        self::assertSame(['service' => 1], $stats->updated);
    }
}
