<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
