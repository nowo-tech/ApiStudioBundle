<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;

/**
 * @extends ServiceEntityRepository<ApiEndpoint>
 */
class ApiEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiEndpoint::class);
    }

    /**
     * Count of the rows whose workspace is in the scope (dashboard).
     */
    public function countInScope(ApiStudioScope $scope): int
    {
        $query = $this->createQueryBuilder('e')->select('COUNT(e.id)')->join('e.service', 's')->join('s.workspace', 'w');
        ApiWorkspaceRepository::applyScope($query, $scope, 'w');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * 1.18 global search (SearchKit): the base query of the endpoint group — endpoints whose workspace is in the scope,
     * by name, as scalar rows (`eid`, `ename`, `epath`, `emethod`, `eupdated`, `efolder` (null: service root, or folders
     * off), `sid`, `sname`, `wid`, `wname` and the workspace's tenant and scope columns). No header, body, query
     * parameter or script column is read.
     */
    public function searchQuery(ApiStudioScope $scope): QueryBuilder
    {
        $query = $this->createQueryBuilder('e')
            ->select('e.id AS eid', 'e.name AS ename', 'e.path AS epath', 'e.method AS emethod', 'e.updatedAt AS eupdated')
            ->addSelect('s.id AS sid', 's.name AS sname', 'w.id AS wid', 'w.name AS wname')
            ->addSelect($this->getClassMetadata()->hasAssociation('folder') ? 'IDENTITY(e.folder) AS efolder' : 'NULLIF(1, 1) AS efolder')
            ->join('e.service', 's')
            ->join('s.workspace', 'w')
            ->orderBy('e.name', 'ASC')->addOrderBy('e.id', 'ASC');
        ApiWorkspaceRepository::applyScope($query, $scope, 'w');
        ApiWorkspaceRepository::selectScopeColumns($query, $scope, 'w');

        return $query;
    }
}
