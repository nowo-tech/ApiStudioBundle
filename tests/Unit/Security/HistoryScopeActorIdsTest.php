<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use UnexpectedValueException;

use function hash;
use function str_repeat;
use function substr;

/**
 * 1.24 `ApiStudioHistoryScope::actorIdsOf()`: the actor ids under which a person's rows may be stored (GDPR erasure
 * and export), with the same shapes as `actorId()`.
 */
final class HistoryScopeActorIdsTest extends TestCase
{
    public function testDefaultIdsOfTheOriginalAndTheCurrentIdentifier(): void
    {
        $scope = new ApiStudioHistoryScope();
        $long  = str_repeat('a', 63);

        self::assertSame(['u:ana@example.test', 'u:deleted-7'], $scope->actorIdsOf(new InMemoryUser('deleted-7', null), 'ana@example.test'));
        self::assertSame(['u:ana@example.test'], $scope->actorIdsOf(new InMemoryUser('ana@example.test', null), 'ana@example.test'));
        self::assertSame(['u:ana@example.test'], $scope->actorIdsOf(new InMemoryUser('ana@example.test', null)));
        self::assertSame(['h:' . substr(hash('sha256', $long), 0, 62), 'u:x'], $scope->actorIdsOf(new InMemoryUser('x', null), $long));
        self::assertSame(['u:x'], $scope->actorIdsOf(new InMemoryUser('x', null), ''));
        self::assertSame([], $scope->actorIdsOf(new stdClass()));
    }

    public function testAHostResolverGivesItsIdOnly(): void
    {
        $scope = new ApiStudioHistoryScope('user', new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'u:0b9e2f3c';
            }
        });

        self::assertSame(['u:0b9e2f3c'], $scope->actorIdsOf(new InMemoryUser('deleted-7', null), 'ana@example.test'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'space' => ['has space'];
        // 1.24: `$` also matched before a trailing newline.
        yield 'trailing newline' => ["u:ana\n"];
    }

    #[DataProvider('malformedIds')]
    public function testAMalformedHostIdIsRefused(string $id): void
    {
        $scope = new ApiStudioHistoryScope('user', new class($id) implements ApiStudioActorResolverInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function actorId(object $user): string
            {
                return $this->id;
            }
        });

        $this->expectException(UnexpectedValueException::class);
        $scope->actorIdsOf(new InMemoryUser('ana', null));
    }
}
