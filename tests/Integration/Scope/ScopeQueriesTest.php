<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Scope;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

/**
 * 1.17: lists, counts and the sidebar narrow by scope in the query (exact), the SQL filter narrows by scope with and
 * without tenancy, and the schema has the per-(tenant,) scope slug key.
 */
final class ScopeQueriesTest extends TestCase
{
    use ScopeOrmTrait;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function tenancyModes(): iterable
    {
        yield 'with tenancy' => [true];
        yield 'without tenancy' => [false];
    }

    #[DataProvider('tenancyModes')]
    public function testRepositoriesNarrowByScope(bool $tenancy): void
    {
        [$registry, $em] = $this->fixture($tenancy);
        $workspaces      = new ApiWorkspaceRepository($this->doctrine($em));
        $services        = new ApiServiceRepository($this->doctrine($em));
        $endpoints       = new ApiEndpointRepository($this->doctrine($em));
        $tenant          = $tenancy ? new ApiStudioTenant('org', 'acme') : ApiStudioTenant::instance();

        $inA = new ApiStudioScope($tenant, $tenancy, null, new WorkspaceScope('project:a'), true);
        self::assertSame(['A'], $this->names($workspaces->findInScope($inA, 50)));
        self::assertSame(1, $workspaces->countInScope($inA));
        self::assertSame(1, $services->countInScope($inA));
        self::assertSame(1, $endpoints->countInScope($inA));
        self::assertSame(['A'], array_map(static fn (array $row): string => $row['wname'], $workspaces->navigationRows($inA)));
        self::assertArrayNotHasKey('wscope', $workspaces->navigationRows($inA)[0]);

        // Exact: another case is another scope.
        $upper = new ApiStudioScope($tenant, $tenancy, null, new WorkspaceScope('project:A'), true);
        self::assertSame([], $workspaces->findInScope($upper, 50));
        self::assertSame([], $workspaces->navigationRows($upper));

        $all = new ApiStudioScope($tenant, $tenancy, null, null, true, true, null);
        self::assertSame(['A', 'B', 'T'], $this->names($workspaces->findInScope($all, 50)));

        $some = new ApiStudioScope($tenant, $tenancy, null, null, true, true, ['project:b']);
        self::assertSame(['B', 'T'], $this->names($workspaces->findInScope($some, 50)));
        self::assertSame(2, $workspaces->countInScope($some));

        $none = new ApiStudioScope($tenant, $tenancy, null, null, true, true, []);
        self::assertSame(['T'], $this->names($workspaces->findInScope($none, 50)));

        $unscoped = new ApiStudioScope($tenant, $tenancy, null, null, true, false);
        self::assertSame(['T'], $this->names($workspaces->findInScope($unscoped, 50)));
        self::assertSame(['T'], array_map(static fn (array $row): string => $row['wname'], $workspaces->navigationRows($unscoped)));
        unset($registry);
    }

    #[DataProvider('tenancyModes')]
    public function testTheSqlFilterNarrowsByScope(bool $tenancy): void
    {
        [, $em] = $this->fixture($tenancy);
        $tenant = $tenancy ? new ApiStudioTenant('org', 'acme') : ApiStudioTenant::instance();
        $filter = $em->getFilters()->enable(TenantSqlFilter::NAME);
        self::assertInstanceOf(TenantSqlFilter::class, $filter);

        foreach ([
            [new ApiStudioScope($tenant, $tenancy, null, new WorkspaceScope('project:a'), true), ['A'], 1],
            [new ApiStudioScope($tenant, $tenancy, null, null, true, true, null), ['A', 'B', 'T'], 2],
            [new ApiStudioScope($tenant, $tenancy, null, null, true, true, ['project:b']), ['B', 'T'], 1],
            [new ApiStudioScope($tenant, $tenancy, null, null, true, true, []), ['T'], 0],
            [new ApiStudioScope($tenant, $tenancy, null, null, true, false), ['T'], 0],
            [new ApiStudioScope($tenant, $tenancy, [0], null, true, true, null), [], 0],
        ] as [$scope, $names, $endpointCount]) {
            TenantSqlFilter::configure($filter, $scope);
            $em->clear();
            self::assertSame($names, $this->names($this->all($em, ApiWorkspace::class)));
            self::assertCount($endpointCount, $this->all($em, ApiEndpoint::class));
        }
    }

    public function testScopesOffLeaveTheFilterToTheTenant(): void
    {
        [, $em] = $this->fixture(true);
        $filter = $em->getFilters()->enable(TenantSqlFilter::NAME);
        self::assertInstanceOf(TenantSqlFilter::class, $filter);
        TenantSqlFilter::configure($filter, new ApiStudioScope(new ApiStudioTenant('org', 'acme'), true));

        self::assertCount(3, $this->all($em, ApiWorkspace::class));
    }

    #[DataProvider('tenancyModes')]
    public function testTheSlugIsUniquePerScope(bool $tenancy): void
    {
        [, $em] = $this->fixture($tenancy);
        /** @var array{uniqueConstraints: array<string, mixed>, indexes: array<string, mixed>} $table */
        $table = $em->getClassMetadata(ApiWorkspace::class)->table;
        $key   = $tenancy ? 'api_studio_uniq_workspace_tenant_scope_slug' : 'api_studio_uniq_workspace_scope_slug';
        self::assertArrayHasKey($key, $table['uniqueConstraints']);
        self::assertArrayNotHasKey('api_studio_uniq_workspace_slug', $table['uniqueConstraints']);
        self::assertArrayNotHasKey('api_studio_uniq_workspace_tenant_slug', $table['uniqueConstraints']);
        self::assertArrayHasKey($tenancy ? 'api_studio_idx_workspace_tenant_scope' : 'api_studio_idx_workspace_scope', $table['indexes']);

        $em->persist($this->workspace($tenancy, 'Dup', 'x', 'project:a'));
        $em->flush();
        $em->persist($this->workspace($tenancy, 'Dup', 'x', 'project:b'));
        $em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $em->persist($this->workspace($tenancy, 'Dup', 'x', 'project:a'));
        $em->flush();
    }

    public function testTheScopeColumnIsBinaryOnMysqlAndMariadb(): void
    {
        foreach (['8.4.0' => 'utf8mb4_0900_bin', '11.4.2-MariaDB' => 'utf8mb4_nopad_bin'] as $version => $collation) {
            $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $version, 'host' => 'unused'], $this->scopeConfiguration());
            $em         = $this->scopeManager($connection, false);
            $mapping    = $em->getClassMetadata(ApiWorkspace::class)->getFieldMapping('hostScope');
            self::assertSame($collation, $mapping->options['collation'] ?? null);
            self::assertSame('host_scope', $mapping->columnName);
        }
    }

    /**
     * @return array{ScopeManagerRegistry, EntityManagerInterface}
     */
    private function fixture(bool $tenancy): array
    {
        $registry = $this->sqliteScopeRegistry($tenancy);
        $em       = $this->scopeEntityManager($registry);
        foreach ([['A', 'payments', 'project:a'], ['B', 'payments', 'project:b'], ['T', 'payments', null]] as [$name, $slug, $scope]) {
            $workspace = $this->workspace($tenancy, $name, $slug, $scope);
            $service   = new ApiService('S' . $name, 's');
            $workspace->addService($service);
            $em->persist($workspace);
            if ($scope !== null) {
                $endpoint = (new ApiEndpoint('E' . $name, 'e'))->setService($service);
                $em->persist($endpoint);
            }
        }
        if ($tenancy) {
            $em->persist($this->workspace(true, 'Z', 'payments', 'project:a', 'zeta'));
        }
        $em->flush();
        $em->clear();

        return [$registry, $em];
    }

    private function workspace(bool $tenancy, string $name, string $slug, ?string $scope, string $tenant = 'acme'): ApiWorkspace
    {
        return (new ApiWorkspace($name, $slug))
            ->assignTenant($tenancy ? new ApiStudioTenant('org', $tenant) : ApiStudioTenant::instance())
            ->assignScope($scope !== null ? new WorkspaceScope($scope) : null);
    }

    private function doctrine(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return $registry;
    }

    /**
     * @param list<ApiWorkspace> $workspaces
     *
     * @return list<string>
     */
    private function names(array $workspaces): array
    {
        $names = array_map(static fn (ApiWorkspace $workspace): string => $workspace->getName(), $workspaces);
        sort($names);

        return $names;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param string|null $slug only the rows with this slug
     *
     * @return list<T>
     */
    private function all(EntityManagerInterface $em, string $class, ?string $slug = null): array
    {
        $query = $em->createQueryBuilder()->select('e')->from($class, 'e');
        if ($slug !== null) {
            $query->where('e.slug = :slug')->setParameter('slug', $slug);
        }
        /** @var list<T> $rows */
        $rows = $query->getQuery()->getResult();

        return $rows;
    }
}
