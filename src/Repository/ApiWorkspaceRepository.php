<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;

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
        }
        /** @var list<array{wid: int, wname: string, wslug: string, sid: int|null, sname: string|null, sprotocol: mixed, eid: int|null, ename: string|null, emethod: mixed, xid: int|null, xname: string|null}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return $rows;
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

        return $workspaces;
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

        if ($scope->visibleIds !== null) {
            $query->andWhere(sprintf('%s.id IN (:nowo_scope_ids)', $alias))
                ->setParameter('nowo_scope_ids', $scope->visibleIds === [] ? [0] : $scope->visibleIds, ArrayParameterType::INTEGER);
        }
    }
}
