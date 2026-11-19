<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;

/**
 * @extends ServiceEntityRepository<ApiService>
 */
class ApiServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiService::class);
    }

    /**
     * Count of the rows whose workspace is in the scope (dashboard).
     */
    public function countInScope(ApiStudioScope $scope): int
    {
        $query = $this->createQueryBuilder('s')->select('COUNT(s.id)')->join('s.workspace', 'w');
        ApiWorkspaceRepository::applyScope($query, $scope, 'w');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * 1.18 global search (SearchKit): the base query of the service group — services whose workspace is in the scope,
     * by name, as scalar rows (`sid`, `sname`, `sprotocol`, `supdated`, `wid`, `wname` and the workspace's tenant and
     * scope columns). No URL, auth, header or script column is read.
     */
    public function searchQuery(ApiStudioScope $scope): QueryBuilder
    {
        $query = $this->createQueryBuilder('s')
            ->select('s.id AS sid', 's.name AS sname', 's.protocol AS sprotocol', 's.updatedAt AS supdated', 'w.id AS wid', 'w.name AS wname')
            ->join('s.workspace', 'w')
            ->orderBy('s.name', 'ASC')->addOrderBy('s.id', 'ASC');
        ApiWorkspaceRepository::applyScope($query, $scope, 'w');
        ApiWorkspaceRepository::selectScopeColumns($query, $scope, 'w');

        return $query;
    }
}
