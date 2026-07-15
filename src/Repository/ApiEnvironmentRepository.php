<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;

/**
 * @extends ServiceEntityRepository<ApiEnvironment>
 */
class ApiEnvironmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiEnvironment::class);
    }
}
