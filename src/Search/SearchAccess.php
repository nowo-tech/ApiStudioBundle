<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Search;

use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;

use function array_keys;
use function array_slice;
use function is_int;
use function is_numeric;

/**
 * Batched per-result checks of the global search (1.18), after the SQL filter: `workspace.view` through
 * {@see ApiStudioAuthorization::can()} (the tenant and scope re-checked on the entity byte-exact, the host's permission
 * checker and, for a scoped workspace, its scope checker), and for endpoints the host's folder checker on every folder
 * above them ({@see FolderAccess::hiddenFolderIds()}).
 *
 * One query loads every workspace of the candidates and one more the folder rows of their services (only with a
 * folder checker); each distinct workspace and folder is decided once (memoised on the request by the bundle). At most
 * {@see self::MAX_CANDIDATES} rows are checked. Stateless (worker-safe).
 */
final readonly class SearchAccess
{
    /** Rows checked per group at most; more candidates make the total inexact (capped to the rows shown). */
    public const MAX_CANDIDATES = 100;

    public function __construct(
        private ApiStudioAuthorization $authorization,
        private ApiWorkspaceRepository $workspaces,
        private ?ApiFolderRepository $folders = null,
        private FolderAccess $folderAccess = new FolderAccess(),
    ) {
    }

    /**
     * The rows the user may open, in their order. Rows need `wid` (and for endpoints `sid` and `efolder`).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function visible(SearchKind $kind, array $rows): array
    {
        $rows = array_slice($rows, 0, self::MAX_CANDIDATES);
        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[self::int($row['wid'] ?? null)] = true;
        }
        $granted = [];
        foreach ($this->workspaces->findByIds(array_keys($ids)) as $id => $workspace) {
            if ($this->authorization->can(ApiStudioAction::WorkspaceView, $workspace)) {
                $granted[$id] = true;
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            if (isset($granted[self::int($row['wid'] ?? null)])) {
                $kept[] = $row;
            }
        }

        return $kind === SearchKind::Endpoint ? $this->outsideHiddenFolders($kept) : $kept;
    }

    /**
     * Endpoints whose folder (and every folder above it) the folder checker lets the user view. A folder the tree
     * does not know is hidden (fail closed).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function outsideHiddenFolders(array $rows): array
    {
        if ($rows === [] || !$this->folderAccess->isActive()) {
            return $rows;
        }

        $services = [];
        $inFolder = false;
        foreach ($rows as $row) {
            if (($row['efolder'] ?? null) !== null) {
                $services[self::int($row['sid'] ?? null)] = true;
                $inFolder                                 = true;
            }
        }
        if (!$inFolder) {
            return $rows;
        }

        $tree  = $this->folders?->treeRows(array_keys($services)) ?? [];
        $known = [];
        foreach ($tree as $folder) {
            $known[$folder['id']] = true;
        }
        $hidden = $this->folderAccess->hiddenFolderIds($tree);

        $kept = [];
        foreach ($rows as $row) {
            $folder = $row['efolder'] ?? null;
            if ($folder === null || (isset($known[self::int($folder)]) && !isset($hidden[self::int($folder)]))) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
