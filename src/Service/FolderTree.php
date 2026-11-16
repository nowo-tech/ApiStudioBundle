<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Throwable;

use function array_splice;
use function array_values;
use function count;
use function max;

/**
 * Rules and writes of the service folder trees (1.14): depth, cycles, moves, reorders and deletes. Stateless: every
 * call works on the entities it is given, inside an explicit transaction of the Api Studio entity manager (no
 * closure kept by the shared service).
 *
 * Errors are {@see DomainException}s whose message is a translation key of the bundle domain
 * (`folder.error.*`).
 */
final class FolderTree
{
    public const ERROR_OTHER_SERVICE = 'folder.error.other_service';
    public const ERROR_CYCLE         = 'folder.error.cycle';
    public const ERROR_DEPTH         = 'folder.error.depth';

    /** Gap between sort orders written by a reorder, so manual values can still be squeezed in between. */
    private const SORT_STEP = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiFolderRepository $folders,
        private readonly int $maxDepth = 8,
    ) {
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Throws when $folder may not sit under $parent (null = service root): another service, itself or one of its
     * own descendants, or deeper than `folders.max_depth` once its subfolders are counted.
     */
    public function assertCanPlaceFolder(ApiFolder $folder, ?ApiFolder $parent): void
    {
        $service = $folder->getService();
        if ($parent instanceof ApiFolder) {
            if ($parent->getService() !== $service) {
                throw new DomainException(self::ERROR_OTHER_SERVICE);
            }
            foreach ($parent->getPath() as $ancestor) {
                if ($ancestor === $folder) {
                    throw new DomainException(self::ERROR_CYCLE);
                }
            }
        }

        $parentDepth = $parent instanceof ApiFolder ? $parent->getDepth() : 0;
        if ($parentDepth + $this->height($folder) > $this->maxDepth) {
            throw new DomainException(self::ERROR_DEPTH);
        }
    }

    /**
     * Throws when an endpoint of $service may not go into $folder (another service).
     */
    public function assertCanPlaceEndpoint(ApiEndpoint $endpoint, ?ApiFolder $folder): void
    {
        if ($folder instanceof ApiFolder && $folder->getService() !== $endpoint->getService()) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }
    }

    /**
     * Moves $folder under $parent (null = root), before the sibling folder $beforeId (null = last), and renumbers the
     * sibling folders.
     */
    public function moveFolder(ApiFolder $folder, ?ApiFolder $parent, ?int $beforeId = null): void
    {
        $this->assertCanPlaceFolder($folder, $parent);
        $service = $folder->getService();
        if (!$service instanceof ApiService) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }

        $this->entityManager->beginTransaction();
        try {
            $folder->setParent($parent);
            $siblings = array_values(array_filter(
                $this->folders->children($service, $parent),
                static fn (ApiFolder $sibling): bool => $sibling !== $folder,
            ));
            $this->renumber($this->insert($siblings, $folder, $beforeId));
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Moves $endpoint into $folder (null = root), before the endpoint $beforeId of that level (null = last), and
     * renumbers the endpoints of the level.
     */
    public function moveEndpoint(ApiEndpoint $endpoint, ?ApiFolder $folder, ?int $beforeId = null): void
    {
        $this->assertCanPlaceEndpoint($endpoint, $folder);
        $service = $endpoint->getService();
        if (!$service instanceof ApiService) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }

        $this->entityManager->beginTransaction();
        try {
            $endpoint->setFolder($folder);
            $siblings = array_values(array_filter(
                $this->folders->endpointsIn($service, $folder),
                static fn (ApiEndpoint $sibling): bool => $sibling !== $endpoint,
            ));
            $this->renumber($this->insert($siblings, $endpoint, $beforeId));
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Deletes $folder; its subfolders and endpoints move up to its parent (or the service root), after the items
     * already there. Nothing else is deleted.
     */
    public function deleteFolder(ApiFolder $folder): void
    {
        $service = $folder->getService();
        if (!$service instanceof ApiService) {
            return;
        }

        $this->entityManager->beginTransaction();
        try {
            $parent = $folder->getParent();

            $folders = array_values(array_filter($this->folders->children($service, $parent), static fn (ApiFolder $f): bool => $f !== $folder));
            foreach ($this->folders->children($service, $folder) as $child) {
                $child->setParent($parent);
                $folders[] = $child;
            }
            $this->renumber($folders);

            $endpoints = $this->folders->endpointsIn($service, $parent);
            foreach ($this->folders->endpointsIn($service, $folder) as $endpoint) {
                $endpoint->setFolder($parent);
                $endpoints[] = $endpoint;
            }
            $this->renumber($endpoints);

            $this->entityManager->remove($folder);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }

    /**
     * Sort order for a new last item of a level.
     *
     * @param list<ApiEndpoint>|list<ApiFolder> $siblings
     */
    public static function nextSortOrder(array $siblings): int
    {
        $last = -self::SORT_STEP;
        foreach ($siblings as $sibling) {
            $last = max($last, $sibling->getSortOrder());
        }

        return $last + self::SORT_STEP;
    }

    /**
     * Levels of folders from $folder down to its deepest descendant (1 when it has no subfolder).
     */
    private function height(ApiFolder $folder): int
    {
        $service = $folder->getService();
        $id      = $folder->getId();
        if (!$service instanceof ApiService || $id === null || $service->getId() === null) {
            return 1;
        }

        $children = [];
        foreach ($this->folders->treeRows([(int) $service->getId()]) as $row) {
            if ($row['parent'] !== null) {
                $children[$row['parent']][] = $row['id'];
            }
        }

        $height = 1;
        $level  = [$id];
        $seen   = [$id => true];
        while (true) {
            $next = [];
            foreach ($level as $node) {
                foreach ($children[$node] ?? [] as $child) {
                    if (!isset($seen[$child])) {
                        $seen[$child] = true;
                        $next[]       = $child;
                    }
                }
            }
            if ($next === []) {
                return $height;
            }
            ++$height;
            $level = $next;
        }
    }

    /**
     * @template T of ApiFolder|ApiEndpoint
     *
     * @param list<T> $siblings
     * @param T $item
     *
     * @return list<T>
     */
    private function insert(array $siblings, ApiFolder|ApiEndpoint $item, ?int $beforeId): array
    {
        $position = count($siblings);
        if ($beforeId !== null) {
            foreach ($siblings as $index => $sibling) {
                if ($sibling->getId() === $beforeId) {
                    $position = $index;

                    break;
                }
            }
        }
        array_splice($siblings, $position, 0, [$item]);

        return $siblings;
    }

    /**
     * @param list<ApiEndpoint>|list<ApiFolder> $items
     */
    private function renumber(array $items): void
    {
        foreach ($items as $index => $item) {
            if ($item->getSortOrder() !== $index * self::SORT_STEP) {
                $item->setSortOrder($index * self::SORT_STEP);
            }
        }
    }
}
