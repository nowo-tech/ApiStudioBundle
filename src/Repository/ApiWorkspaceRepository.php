<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;

use function array_filter;
use function array_values;
use function is_array;
use function sprintf;

/**
 * @extends ServiceEntityRepository<ApiWorkspace>
 */
class ApiWorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiWorkspace::class);
    }

    /**
     * Flat rows of the sidebar tree (workspace → service → endpoint → request example) in ONE query, ordered like the
     * entity collections (#[OrderBy]); missing levels are null (LEFT JOIN). Scalar hydration: no entities are loaded,
     * so building the tree does not trigger one lazy load per workspace / service / endpoint.
     *
     * @return list<array{wid: int, wname: string, wslug: string, sid: int|null, sname: string|null, sprotocol: mixed,
     *                    eid: int|null, ename: string|null, emethod: mixed, xid: int|null, xname: string|null}>
     */
    public function navigationRows(?ApiStudioScope $scope = null): array
    {
        $query = $this->createQueryBuilder('w')
            ->select('w.id AS wid', 'w.name AS wname', 'w.slug AS wslug')
            ->addSelect('s.id AS sid', 's.name AS sname', 's.protocol AS sprotocol')
            ->addSelect('e.id AS eid', 'e.name AS ename', 'e.method AS emethod')
            ->addSelect('x.id AS xid', 'x.name AS xname')
            ->leftJoin('w.services', 's')
            ->leftJoin('s.endpoints', 'e')
            ->leftJoin('e.requestExamples', 'x')
            ->orderBy('w.name', 'ASC')->addOrderBy('w.id', 'ASC')
            ->addOrderBy('s.name', 'ASC')->addOrderBy('s.id', 'ASC')
            ->addOrderBy('e.sortOrder', 'ASC')->addOrderBy('e.name', 'ASC')->addOrderBy('e.id', 'ASC')
            ->addOrderBy('x.sortOrder', 'ASC')->addOrderBy('x.name', 'ASC')->addOrderBy('x.id', 'ASC');
        if ($scope instanceof ApiStudioScope) {
            self::applyScope($query, $scope, 'w');
            self::selectScopeColumns($query, $scope, 'w');
        }
        /** @var list<array{wid: int, wname: string, wslug: string, wtenanttype?: string, wtenantid?: string, wscope?: string, sid: int|null, sname: string|null, sprotocol: mixed, eid: int|null, ename: string|null, emethod: mixed, xid: int|null, xname: string|null}> $rows */
        $rows = $query->getQuery()->getArrayResult();
        if (!$scope instanceof ApiStudioScope || (!$scope->tenancy && !$scope->scopes)) {
            return $rows;
        }

        // Exact-key rule (1.17, audit UA-L1): the tenant and scope filters of the query are re-checked byte-exact.
        $kept = [];
        foreach ($rows as $row) {
            if (self::rowInScope($row, $scope)) {
                unset($row['wscope'], $row['wtenanttype'], $row['wtenantid']);
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * 1.18 global search (SearchKit): the base query of the workspace group — the workspaces of the scope (tenant, host
     * scope, visible ids), by name, as scalar rows (`wid`, `wname`, `wupdated`, plus the tenant and scope columns that
     * {@see self::rowInScope()} re-checks byte-exact). SearchKit adds the escaped `LIKE` and the limit.
     */
    public function searchQuery(ApiStudioScope $scope): QueryBuilder
    {
        $query = $this->createQueryBuilder('w')
            ->select('w.id AS wid', 'w.name AS wname', 'w.updatedAt AS wupdated')
            ->orderBy('w.name', 'ASC')->addOrderBy('w.id', 'ASC');
        self::applyScope($query, $scope, 'w');
        self::selectScopeColumns($query, $scope, 'w');

        return $query;
    }

    /**
     * 1.18: the workspaces of $ids in one query (the search checks `workspace.view` on each).
     *
     * @param list<int> $ids
     *
     * @return array<int, ApiWorkspace> id => workspace
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<ApiWorkspace> $workspaces */
        $workspaces = $this->createQueryBuilder('w')
            ->where('w.id IN (:nowo_api_studio_ids)')
            ->setParameter('nowo_api_studio_ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            // Checked, never changed: not tracked by the unit of work (audit VG-4).
            ->setHint(Query::HINT_READ_ONLY, true)
            ->getResult();

        $byId = [];
        foreach ($workspaces as $workspace) {
            $byId[(int) $workspace->getId()] = $workspace;
        }

        return $byId;
    }

    /**
     * Exact-key rule (1.17, audit UA-L1): whether a scalar row's workspace tenant (`wtenanttype`, `wtenantid`) and scope
     * (`wscope`) are those of $scope, compared byte-exact (`===`) after the SQL filter.
     *
     * @param array<string, mixed> $row
     */
    public static function rowInScope(array $row, ApiStudioScope $scope): bool
    {
        $tenant = !$scope->tenancy || ((string) ($row['wtenanttype'] ?? '') === $scope->tenant->type && (string) ($row['wtenantid'] ?? '') === $scope->tenant->id);

        return $tenant && $scope->containsScope((string) ($row['wscope'] ?? ''));
    }

    /**
     * 1.18: the tenant and scope columns {@see self::rowInScope()} re-checks (mapped only with tenancy / scopes on).
     */
    public static function selectScopeColumns(QueryBuilder $query, ApiStudioScope $scope, string $alias): void
    {
        if ($scope->tenancy) {
            $query->addSelect(sprintf('%1$s.tenantType AS wtenanttype', $alias), sprintf('%1$s.tenantId AS wtenantid', $alias));
        }
        if ($scope->scopes) {
            $query->addSelect(sprintf('%s.hostScope AS wscope', $alias));
        }
    }

    /**
     * 1.18: the rows that pass {@see self::rowInScope()}, without the tenant columns, `wscope` always set (`''`: tenant
     * level or scopes off).
     *
     * @param array<mixed> $rows scalar rows of a query
     *
     * @return list<array<string, mixed>>
     */
    public static function exactSearchRows(array $rows, ApiStudioScope $scope): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            if (!self::rowInScope($row, $scope)) {
                continue;
            }
            $row['wscope'] = (string) ($row['wscope'] ?? '');
            unset($row['wtenanttype'], $row['wtenantid']);
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * Workspaces of the scope, by name (dashboard, workspace list).
     *
     * @return list<ApiWorkspace>
     */
    public function findInScope(ApiStudioScope $scope, int $limit, int $offset = 0): array
    {
        $query = $this->createQueryBuilder('w')->orderBy('w.name', 'ASC')->addOrderBy('w.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset);
        self::applyScope($query, $scope, 'w');

        /** @var list<ApiWorkspace> $workspaces */
        $workspaces = $query->getQuery()->getResult();
        if (!$scope->tenancy && !$scope->scopes) {
            return $workspaces;
        }

        // Exact-key rule (1.17, audit UA-L1): the tenant and scope filters of the query are re-checked byte-exact.
        return array_values(array_filter($workspaces, static fn (ApiWorkspace $workspace): bool => $scope->contains($workspace)));
    }

    public function countInScope(ApiStudioScope $scope): int
    {
        $query = $this->createQueryBuilder('w')->select('COUNT(w.id)');
        self::applyScope($query, $scope, 'w');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * Narrows a query on workspaces aliased $alias to the scope: the tenant (when tenancy is on) and the visible ids
     * (when the permission checker lists them; an empty list matches nothing).
     */
    public static function applyScope(QueryBuilder $query, ApiStudioScope $scope, string $alias): void
    {
        if ($scope->tenancy) {
            $query->andWhere(sprintf('%1$s.tenantType = :nowo_scope_tenant_type AND %1$s.tenantId = :nowo_scope_tenant_id', $alias))
                ->setParameter('nowo_scope_tenant_type', $scope->tenant->type)
                ->setParameter('nowo_scope_tenant_id', $scope->tenant->id);
        }

        if ($scope->scopes) {
            self::applyHostScope($query, $scope, $alias);
        }

        if ($scope->visibleIds !== null) {
            $query->andWhere(sprintf('%s.id IN (:nowo_scope_ids)', $alias))
                ->setParameter('nowo_scope_ids', $scope->visibleIds === [] ? [0] : $scope->visibleIds, ArrayParameterType::INTEGER);
        }
    }

    /**
     * 1.17: the host scope part of {@see self::applyScope()} (`host_scope` is mapped only with `scope.enabled`).
     */
    private static function applyHostScope(QueryBuilder $query, ApiStudioScope $scope, string $alias): void
    {
        if ($scope->hostScope instanceof WorkspaceScope) {
            $query->andWhere(sprintf('%s.hostScope = :nowo_scope_host', $alias))->setParameter('nowo_scope_host', $scope->hostScope->value);

            return;
        }

        if (!$scope->tenantListAll || $scope->visibleScopes === []) {
            $query->andWhere(sprintf("%s.hostScope = ''", $alias));

            return;
        }

        if ($scope->visibleScopes !== null) {
            $query->andWhere(sprintf("(%1\$s.hostScope = '' OR %1\$s.hostScope IN (:nowo_scope_visible))", $alias))
                ->setParameter('nowo_scope_visible', $scope->visibleScopes, ArrayParameterType::STRING);
        }
    }
}
