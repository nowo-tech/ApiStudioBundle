<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\FolderAccess;

use function count;
use function is_string;
use function mb_substr;
use function preg_replace;
use function spl_object_id;
use function trim;

/**
 * Folders of one import into one service (1.14): finds the folder of a name under a parent (an existing one of the
 * service the importing user may edit, or one created earlier in the same import) or creates it, and caps the depth
 * at `folders.max_depth`.
 *
 * Audit RJ-M1: an existing folder the user may not edit (hidden or read-only for a host folder checker) is never
 * reused, a sibling of the same name is created instead; the importers only set auth on folders this import created
 * ({@see self::isCreated()}), never on reused ones.
 *
 * Built per import call and dropped with it: the in-memory index never outlives the call (worker-safe).
 */
final class FolderImportHelper
{
    /** @var array<string, ApiFolder> parent key + name => folder */
    private array $index = [];

    /** @var array<string, int> parent key => folders under it (existing and created), for the sort order */
    private array $siblings = [];

    /** @var array<int, true> spl_object_id of the folders this import created */
    private array $createdIds = [];

    private int $merged = 0;

    public function __construct(
        private readonly ApiService $service,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ApiFolderRepository $repository,
        private readonly int $maxDepth,
        private readonly ?FolderAccess $folderAccess = null,
    ) {
    }

    /**
     * The folder $name under $parent (null = service root), created when missing. Null when it would be deeper than
     * the maximum depth: the caller keeps $parent (its content is merged into it) and the merge is counted.
     */
    public function child(?ApiFolder $parent, mixed $name, ?string $description = null): ?ApiFolder
    {
        $depth = ($parent?->getDepth() ?? 0) + 1;
        if ($depth > $this->maxDepth) {
            // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
            ++$this->merged;

            return null;
        }

        $name = self::cleanName($name);
        $key  = $this->parentKey($parent);
        $this->load($parent, $key);
        if (isset($this->index[$key . "\0" . $name])) {
            return $this->index[$key . "\0" . $name];
        }

        $folder = new ApiFolder($name);
        $folder->setService($this->service);
        $folder->setParent($parent);
        $folder->setDescription($description !== null && trim($description) !== '' ? $description : null);
        $folder->setSortOrder(($this->siblings[$key] ?? 0) * 10);
        $this->entityManager->persist($folder);
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->index[$key . "\0" . $name] = $folder;
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->siblings[$key] = ($this->siblings[$key] ?? 0) + 1;
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->createdIds[spl_object_id($folder)] = true;

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

    /** Whether this import created $folder (only those get the imported auth and description). */
    public function isCreated(ApiFolder $folder): bool
    {
        return isset($this->createdIds[spl_object_id($folder)]);
    }

    /** Folders deeper than the maximum depth whose content was merged into their deepest allowed parent. */
    public function mergedCount(): int
    {
        return $this->merged;
    }

    public function createdCount(): int
    {
        return count($this->createdIds);
    }

    /**
     * Folder or endpoint name from an imported document (RJ-L4): strings only, without control, zero-width or bidi
     * characters (NUL included), trimmed, at most 128 characters; anything else is $default.
     */
    public static function cleanName(mixed $name, string $default = 'Folder'): string
    {
        if (!is_string($name)) {
            return $default;
        }
        $clean = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);
        $clean = trim((string) $clean);

        return $clean !== '' ? mb_substr($clean, 0, 128) : $default;
    }

    private function parentKey(?ApiFolder $parent): string
    {
        if (!$parent instanceof ApiFolder) {
            return 'root';
        }

        return $parent->getId() !== null ? 'id' . $parent->getId() : 'obj' . spl_object_id($parent);
    }

    /**
     * Indexes the existing folders under $parent once (only stored parents of a stored service have any): every one
     * counts for the sort order, only the editable ones can be reused.
     */
    private function load(?ApiFolder $parent, string $key): void
    {
        if (isset($this->siblings[$key])) {
            return;
        }
        // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
        $this->siblings[$key] = 0;
        if (!$this->repository instanceof ApiFolderRepository || $this->service->getId() === null || ($parent instanceof ApiFolder && $parent->getId() === null)) {
            return;
        }

        foreach ($this->repository->children($this->service, $parent) as $folder) {
            // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
            ++$this->siblings[$key];
            if ($this->folderAccess instanceof FolderAccess && !$this->folderAccess->can(ApiStudioAction::WorkspaceEdit, $folder)) {
                continue;
            }
            // @igor-ignore - Per-import object built and dropped inside one import call (excluded from the container), not shared state.
            $this->index[$key . "\0" . $folder->getName()] ??= $folder;
        }
    }
}
