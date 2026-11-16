<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;

/**
 * Folders of the service trees (1.14). Only queried with `nowo_api_studio.folders.enabled`.
 *
 * @extends ServiceEntityRepository<ApiFolder>
 */
class ApiFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiFolder::class);
    }

    /**
     * Folders of the services, as scalar rows in tree order (one query for every tree on a page).
     *
     * @param list<int> $serviceIds
     *
     * @return list<array{id: int, name: string, parent: int|null, service: int, workspace: int, sortOrder: int, createdBy: string|null}>
     */
    public function treeRows(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        /** @var list<array{id: int|string, name: string, parent: int|string|null, service: int|string, workspace: int|string, sortOrder: int|string, createdBy: string|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.id AS id', 'f.name AS name', 'IDENTITY(f.parent) AS parent', 'IDENTITY(f.service) AS service', 'IDENTITY(s.workspace) AS workspace', 'f.sortOrder AS sortOrder', 'f.createdBy AS createdBy')
            ->join('f.service', 's')
            ->where('f.service IN (:services)')
            ->setParameter('services', $serviceIds, ArrayParameterType::INTEGER)
            ->orderBy('f.sortOrder', 'ASC')->addOrderBy('f.name', 'ASC')->addOrderBy('f.id', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'parent'    => $row['parent'] === null ? null : (int) $row['parent'],
            'service'   => (int) $row['service'],
            'workspace' => (int) $row['workspace'],
            'sortOrder' => (int) $row['sortOrder'],
            'createdBy' => $row['createdBy'],
        ], $rows);
    }

    /**
     * Folder id of each endpoint of the services (null = service root).
     *
     * @param list<int> $serviceIds
     *
     * @return array<int, int|null> endpoint id => folder id
     */
    public function endpointFolderIds(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        /** @var list<array{id: int|string, folder: int|string|null}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('e.id AS id', 'IDENTITY(e.folder) AS folder')
            ->from(ApiEndpoint::class, 'e')
            ->where('e.service IN (:services)')
            ->setParameter('services', $serviceIds, ArrayParameterType::INTEGER)
            ->getQuery()->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row['folder'] === null ? null : (int) $row['folder'];
        }

        return $map;
    }

    /**
     * Folders directly under $parent (null = the service root), in tree order.
     *
     * @return list<ApiFolder>
     */
    public function children(ApiService $service, ?ApiFolder $parent): array
    {
        $query = $this->createQueryBuilder('f')
            ->where('f.service = :service')->setParameter('service', $service)
            ->orderBy('f.sortOrder', 'ASC')->addOrderBy('f.name', 'ASC')->addOrderBy('f.id', 'ASC');
        if ($parent instanceof ApiFolder) {
            $query->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        } else {
            $query->andWhere('f.parent IS NULL');
        }

        /** @var list<ApiFolder> $folders */
        $folders = $query->getQuery()->getResult();

        return $folders;
    }

    /**
     * Endpoints directly in $folder (null = the service root), in tree order.
     *
     * @return list<ApiEndpoint>
     */
    public function endpointsIn(ApiService $service, ?ApiFolder $folder): array
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('e')->from(ApiEndpoint::class, 'e')
            ->where('e.service = :service')->setParameter('service', $service)
            ->orderBy('e.sortOrder', 'ASC')->addOrderBy('e.name', 'ASC')->addOrderBy('e.id', 'ASC');
        if ($folder instanceof ApiFolder) {
            $query->andWhere('e.folder = :folder')->setParameter('folder', $folder);
        } else {
            $query->andWhere('e.folder IS NULL');
        }

        /** @var list<ApiEndpoint> $endpoints */
        $endpoints = $query->getQuery()->getResult();

        return $endpoints;
    }

    /**
     * Every folder of the service (for selects), in tree order of the rows (not nested).
     *
     * @return list<ApiFolder>
     */
    public function findByService(ApiService $service): array
    {
        /** @var list<ApiFolder> $folders */
        $folders = $this->createQueryBuilder('f')
            ->where('f.service = :service')->setParameter('service', $service)
            ->orderBy('f.sortOrder', 'ASC')->addOrderBy('f.name', 'ASC')->addOrderBy('f.id', 'ASC')
            ->getQuery()->getResult();

        return $folders;
    }
}
