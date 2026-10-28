<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

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
    public function navigationRows(): array
    {
        /** @var list<array{wid: int, wname: string, wslug: string, sid: int|null, sname: string|null, sprotocol: mixed, eid: int|null, ename: string|null, emethod: mixed, xid: int|null, xname: string|null}> $rows */
        $rows = $this->createQueryBuilder('w')
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
            ->addOrderBy('x.sortOrder', 'ASC')->addOrderBy('x.name', 'ASC')->addOrderBy('x.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
