<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
