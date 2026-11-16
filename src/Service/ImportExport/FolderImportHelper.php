<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;

use function spl_object_id;

/**
 * Folders of one import into one service (1.14): finds the folder of a name under a parent (an existing one of the
 * service, or one created earlier in the same import) or creates it, and caps the depth at `folders.max_depth`.
 *
 * Built per import call and dropped with it: the in-memory index never outlives the call (worker-safe).
 */
final class FolderImportHelper
{
    /** @var array<string, ApiFolder> parent key + name => folder */
    private array $index = [];

    /** @var array<string, true> parent keys already loaded from the database */
    private array $loaded = [];

    private int $merged = 0;

    private int $created = 0;

    public function __construct(
        private readonly ApiService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ApiFolderRepository $repository,
        private readonly int $maxDepth,
    ) {
    }

    /**
     * The folder $name under $parent (null = service root), created when missing. Null when it would be deeper than
     * the maximum depth: the caller keeps $parent (its content is merged into it) and the merge is counted.
     */
    public function child(?ApiFolder $parent, string $name, ?string $description = null): ?ApiFolder
    {
        $depth = ($parent?->getDepth() ?? 0) + 1;
        if ($depth > $this->maxDepth) {
            // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
            ++$this->merged;

            return null;
        }

        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 128) : 'Folder';
        $key  = $this->parentKey($parent);
        $this->load($parent, $key);
        if (isset($this->index[$key . "\0" . $name])) {
            return $this->index[$key . "\0" . $name];
        }

        $siblings = 0;
        foreach (array_keys($this->index) as $indexKey) {
            if (str_starts_with($indexKey, $key . "\0")) {
                ++$siblings;
            }
        }

        $folder = new ApiFolder($name);
        $folder->setService($this->service);
        $folder->setParent($parent);
        $folder->setDescription($description !== null && trim($description) !== '' ? $description : null);
        $folder->setSortOrder($siblings * 10);
        $this->entityManager->persist($folder);
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->index[$key . "\0" . $name] = $folder;
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        ++$this->created;

        return $folder;
    }

    /**
     * The folder at $path (names from the top level down), created as needed; capped at the maximum depth.
     *
     * @param list<string> $path
     */
    public function path(array $path): ?ApiFolder
    {
        $folder = null;
        foreach ($path as $name) {
            $child = $this->child($folder, $name);
            if (!$child instanceof ApiFolder) {
                break;
            }
            $folder = $child;
        }

        return $folder;
    }

    /** Folders deeper than the maximum depth whose content was merged into their deepest allowed parent. */
    public function mergedCount(): int
    {
        return $this->merged;
    }

    public function createdCount(): int
    {
        return $this->created;
    }

    private function parentKey(?ApiFolder $parent): string
    {
        if (!$parent instanceof ApiFolder) {
            return 'root';
        }

        return $parent->getId() !== null ? 'id' . $parent->getId() : 'obj' . spl_object_id($parent);
    }

    /**
     * Indexes the existing folders under $parent once (only stored parents of a stored service have any).
     */
    private function load(?ApiFolder $parent, string $key): void
    {
        if (isset($this->loaded[$key])) {
            return;
        }
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->loaded[$key] = true;
        if (!$this->repository instanceof ApiFolderRepository || $this->service->getId() === null || ($parent instanceof ApiFolder && $parent->getId() === null)) {
            return;
        }

        foreach ($this->repository->children($this->service, $parent) as $folder) {
            // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
            $this->index[$key . "\0" . $folder->getName()] ??= $folder;
        }
    }
}
