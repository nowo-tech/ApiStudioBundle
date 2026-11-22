<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Query;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceCriteria;
use Nowo\ApiStudioBundle\Query\ApiWorkspacePage;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceSort;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function range;
use function str_repeat;

use const PHP_INT_MAX;

/**
 * 1.19 (DDD-host checklist B7): the criteria of the workspace query port are immutable, bounded and never fold a key.
 */
final class ApiWorkspaceCriteriaTest extends TestCase
{
    public function testDefaults(): void
    {
        $tenant   = new ApiStudioTenant('org', 'acme');
        $criteria = ApiWorkspaceCriteria::forTenant($tenant);

        self::assertSame($tenant, $criteria->tenant);
        self::assertNull($criteria->scopeKey);
        self::assertNull($criteria->ids);
        self::assertNull($criteria->text);
        self::assertNull($criteria->enabled);
        self::assertSame(ApiWorkspaceSort::Name, $criteria->sort);
        self::assertFalse($criteria->descending);
        self::assertSame(1, $criteria->page);
        self::assertSame(20, $criteria->limit);
        self::assertSame(0, $criteria->offset());
    }

    public function testWithersReturnCopiesAndKeepTheOtherFilters(): void
    {
        $base     = ApiWorkspaceCriteria::forTenant(ApiStudioTenant::instance());
        $criteria = $base->inScope(new WorkspaceScope('project:1'))
            ->withIds([3, 1, 3])
            ->matching('  Pay  ')
            ->withEnabled(false)
            ->sortedBy(ApiWorkspaceSort::UpdatedAt, true)
            ->onPage(3, 10);

        self::assertNull($base->scopeKey, 'immutable');
        self::assertSame('project:1', $criteria->scopeKey);
        self::assertSame([3, 1], $criteria->ids);
        self::assertSame('Pay', $criteria->text);
        self::assertFalse($criteria->enabled);
        self::assertSame(ApiWorkspaceSort::UpdatedAt, $criteria->sort);
        self::assertTrue($criteria->descending);
        self::assertSame(20, $criteria->offset());

        self::assertSame('', $criteria->inScope(null)->scopeKey, 'null scope = tenant level only');
        self::assertNull($criteria->inScope(null)->inAnyScope()->scopeKey);
        self::assertNull($criteria->matching('   ')->text);
        self::assertNull($criteria->matching(null)->text);
        self::assertNull($criteria->withEnabled(null)->enabled);
        self::assertTrue($criteria->withEnabled(true)->enabled);
        self::assertSame([3, 1], $criteria->matching(null)->ids);
    }

    public function testBounds(): void
    {
        $criteria = ApiWorkspaceCriteria::forTenant(ApiStudioTenant::instance());

        self::assertSame(1, $criteria->onPage(0, 0)->page);
        self::assertSame(1, $criteria->onPage(-5, 0)->limit);
        self::assertSame(ApiWorkspaceCriteria::MAX_LIMIT, $criteria->onPage(1, 5000)->limit);
        self::assertSame(ApiWorkspaceCriteria::MAX_PAGE, $criteria->onPage(PHP_INT_MAX)->page);
        self::assertSame(ApiWorkspaceCriteria::MAX_TEXT, mb_strlen((string) $criteria->matching(str_repeat('é', 300))->text));
        self::assertSame([], $criteria->withIds([])->ids, '[] matches nothing');
        self::assertCount(ApiWorkspaceCriteria::MAX_IDS, (array) $criteria->withIds(range(1, ApiWorkspaceCriteria::MAX_IDS))->ids);
    }

    public function testTooManyIdsAreRefused(): void
    {
        $this->expectException(LogicException::class);

        ApiWorkspaceCriteria::forTenant(ApiStudioTenant::instance())->withIds(range(1, ApiWorkspaceCriteria::MAX_IDS + 1));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidIds(): iterable
    {
        yield 'zero' => [[0]];
        yield 'negative' => [[-1]];
        yield 'numeric string' => [['7']];
        yield 'float' => [[1.0]];
    }

    /**
     * @param array<mixed> $ids
     */
    #[DataProvider('invalidIds')]
    public function testInvalidIdsAreRefused(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiWorkspaceCriteria::forTenant(ApiStudioTenant::instance())->withIds($ids);
    }

    public function testPageCountsPages(): void
    {
        self::assertSame(0, (new ApiWorkspacePage([], 0, 1, 20))->pages());
        self::assertSame(1, (new ApiWorkspacePage([], 20, 1, 20))->pages());
        self::assertSame(2, (new ApiWorkspacePage([], 21, 1, 20))->pages());
    }
}
