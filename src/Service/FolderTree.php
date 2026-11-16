<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Service\ImportExport\FolderImportHelper;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_splice;
use function array_values;
use function count;
use function max;

/**
 * Rules and writes of the service folder trees (1.14): depth, cycles, saves, moves, reorders and deletes. Stateless:
 * every call works on the entities it is given.
 *
 * Every write runs in its own transaction on the Api Studio entity manager and starts by locking the service row
 * (PESSIMISTIC_WRITE), so the writes of one service tree are serialised; the rules (parent chain, depth with the
 * subtree height, siblings) are then checked against rows read inside that transaction (audit RJ-M2), never against
 * entities loaded before it. Under contention the database may still answer a lock timeout or a deadlock
 * ({@see RetryableException}); callers report it as a retryable conflict.
 *
 * Errors are {@see DomainException}s whose message is a translation key of the bundle domain (`folder.error.*`).
 */
final class FolderTree
{
    public const ERROR_OTHER_SERVICE = 'folder.error.other_service';
    public const ERROR_CYCLE         = 'folder.error.cycle';
    public const ERROR_DEPTH         = 'folder.error.depth';
    public const ERROR_GONE          = 'folder.error.gone';

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
     * Throws when $folder may not sit under $parent (null = service root): another service, a parent that no longer
     * exists, itself or one of its own descendants, or deeper than `folders.max_depth` once its subfolders are
     * counted. Reads the current rows of the service tree.
     */
    public function assertCanPlaceFolder(ApiFolder $folder, ?ApiFolder $parent): void
    {
        $service = $folder->getService();
        if ($parent instanceof ApiFolder && $parent->getService() !== $service) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }
        if (!$service instanceof ApiService || $service->getId() === null) {
            return;
        }

        /** @var array<int, int|null> $parents folder id => parent id */
        $parents = [];
        foreach ($this->folders->treeRows([(int) $service->getId()]) as $row) {
            $parents[$row['id']] = $row['parent'];
        }

        $parentDepth = 0;
        if ($parent instanceof ApiFolder) {
            $parentId = $parent->getId();
            if ($parentId === null || !array_key_exists($parentId, $parents)) {
                throw new DomainException(self::ERROR_GONE);
            }
            $seen = [];
            for ($node = $parentId; $node !== null; $node = $parents[$node] ?? null) {
                if ($node === $folder->getId() || isset($seen[$node])) {
                    throw new DomainException(self::ERROR_CYCLE);
                }
                $seen[$node] = true;
                ++$parentDepth;
            }
        }

        if ($parentDepth + $this->height($folder, $parents) > $this->maxDepth) {
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
     * Creates or updates $folder (its parent already set, e.g. by a form) once its place is checked under the lock.
     */
    public function saveFolder(ApiFolder $folder): void
    {
        $service = $this->serviceOf($folder->getService());
        $folder->setName(FolderImportHelper::cleanName($folder->getName()));
        $this->begin($service);
        try {
            if ($folder->getId() !== null && !$this->exists($service, $folder)) {
                throw new DomainException(self::ERROR_GONE);
            }
            $this->assertCanPlaceFolder($folder, $folder->getParent());
            if ($folder->getId() === null) {
                $folder->setSortOrder(self::nextSortOrder($this->folders->children($service, $folder->getParent())));
                $this->entityManager->persist($folder);
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Moves $folder under $parent (null = root), before the sibling folder $beforeId (null = last), and renumbers the
     * sibling folders.
     */
    public function moveFolder(ApiFolder $folder, ?ApiFolder $parent, ?int $beforeId = null): void
    {
        if ($parent instanceof ApiFolder && $parent->getService() !== $folder->getService()) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }
        $service = $this->serviceOf($folder->getService());

        $this->begin($service);
        try {
            if (!$this->exists($service, $folder)) {
                throw new DomainException(self::ERROR_GONE);
            }
            $this->assertCanPlaceFolder($folder, $parent);
            $folder->setParent($parent);
            $siblings = array_values(array_filter(
                $this->folders->children($service, $parent),
                static fn (ApiFolder $sibling): bool => $sibling !== $folder,
            ));
            $this->renumber($this->insert($siblings, $folder, $beforeId));
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();

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
        $service = $this->serviceOf($endpoint->getService());

        $this->begin($service);
        try {
            if ($folder instanceof ApiFolder && !$this->exists($service, $folder)) {
                throw new DomainException(self::ERROR_GONE);
            }
            $endpoint->setFolder($folder);
            $siblings = array_values(array_filter(
                $this->folders->endpointsIn($service, $folder),
                static fn (ApiEndpoint $sibling): bool => $sibling !== $endpoint,
            ));
            $this->renumber($this->insert($siblings, $endpoint, $beforeId));
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Deletes $folder; its subfolders and endpoints move up to its parent (or the service root), after the items
     * already there. Nothing else is deleted.
     *
     * @return array{folders: list<int>, endpoints: list<int>} ids of the subfolders and endpoints that moved up
     */
    public function deleteFolder(ApiFolder $folder): array
    {
        $moved   = ['folders' => [], 'endpoints' => []];
        $service = $folder->getService();
        if (!$service instanceof ApiService) {
            return $moved;
        }

        $this->begin($service);
        try {
            // The parent as stored now (a concurrent move may have changed it since $folder was loaded).
            if ($folder->getId() === null || !$this->exists($service, $folder)) {
                throw new DomainException(self::ERROR_GONE);
            }
            $this->entityManager->refresh($folder);
            $parent = $folder->getParent();

            $folders = array_values(array_filter($this->folders->children($service, $parent), static fn (ApiFolder $f): bool => $f !== $folder));
            foreach ($this->folders->children($service, $folder) as $child) {
                $child->setParent($parent);
                $folders[]          = $child;
                $moved['folders'][] = (int) $child->getId();
            }
            $this->renumber($folders);

            $endpoints = $this->folders->endpointsIn($service, $parent);
            foreach ($this->folders->endpointsIn($service, $folder) as $endpoint) {
                $endpoint->setFolder($parent);
                $endpoints[]          = $endpoint;
                $moved['endpoints'][] = (int) $endpoint->getId();
            }
            $this->renumber($endpoints);

            $this->entityManager->remove($folder);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }

        return $moved;
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
     * Opens the transaction and locks the service row: the writes of one service tree are serialised.
     */
    private function begin(ApiService $service): void
    {
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->lock($service, LockMode::PESSIMISTIC_WRITE);
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Rolls back; when the rollback itself fails (MySQL after a deadlock: the savepoint is gone, error 1305) the
     * connection is closed so its transaction nesting level is reset (audit RJ-D-L2). The caller rethrows the first
     * error (a {@see RetryableException} answers 409).
     */
    private function rollback(): void
    {
        try {
            $this->entityManager->rollback();
        } catch (Throwable) {
            $this->entityManager->getConnection()->close();
        }
    }

    private function commit(): void
    {
        $this->entityManager->flush();
        $this->entityManager->commit();
    }

    private function exists(ApiService $service, ApiFolder $folder): bool
    {
        foreach ($this->folders->treeRows([(int) $service->getId()]) as $row) {
            if ($row['id'] === $folder->getId()) {
                return true;
            }
        }

        return false;
    }

    private function serviceOf(?ApiService $service): ApiService
    {
        if (!$service instanceof ApiService) {
            throw new DomainException(self::ERROR_OTHER_SERVICE);
        }

        return $service;
    }

    /**
     * Levels of folders from $folder down to its deepest descendant (1 when it has no subfolder).
     *
     * @param array<int, int|null> $parents folder id => parent id (current rows)
     */
    private function height(ApiFolder $folder, array $parents): int
    {
        $id = $folder->getId();
        if ($id === null) {
            return 1;
        }

        $children = [];
        foreach ($parents as $child => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $child;
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
