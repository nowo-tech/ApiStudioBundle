<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use BackedEnum;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_values;
use function is_int;
use function is_scalar;

/**
 * Builds sidebar navigation tree (workspaces → services → [folders →] endpoints → request examples).
 *
 * Rendered on every studio page: the tree comes from one scalar query ({@see ApiWorkspaceRepository::navigationRows()})
 * instead of walking lazy collections (1 + W + S + E queries). With folders (1.14) two more scalar queries nest the
 * endpoints in their folders ({@see ApiFolderRepository::treeRows()} / `endpointFolderIds()`), whatever the tree size.
 */
final class StudioNavigationProvider
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?ApiStudioAuthorization $authorization = null,
        private readonly ?ApiFolderRepository $folderRepository = null,
        private readonly bool $foldersEnabled = false,
        private readonly ?FolderAccess $folderAccess = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function buildTree(): array
    {
        /** @var array<int, array<string, mixed>> $tree workspace id => node (services keyed by id while building) */
        $tree = [];
        // Only the workspaces the current request may see (tenant + visible ids).
        foreach ($this->workspaceRepository->navigationRows($this->authorization?->scope()) as $row) {
            $wid = $row['wid'];
            $tree[$wid] ??= [
                'id'       => $wid,
                'name'     => $row['wname'],
                'slug'     => $row['wslug'],
                'url'      => $this->urlGenerator->generate('nowo_api_studio_workspace_show', ['id' => $wid]),
                'services' => [],
            ];

            $sid = $row['sid'];
            if ($sid === null) {
                continue;
            }
            $tree[$wid]['services'][$sid] ??= [
                'id'        => $sid,
                'name'      => $row['sname'],
                'protocol'  => self::enumValue($row['sprotocol']),
                'url'       => $this->urlGenerator->generate('nowo_api_studio_service_show', ['workspaceId' => $wid, 'id' => $sid]),
                'endpoints' => [],
                'folders'   => [],
            ];

            $eid = $row['eid'];
            if ($eid === null) {
                continue;
            }
            $endpointParams = ['workspaceId' => $wid, 'serviceId' => $sid, 'id' => $eid];
            $tree[$wid]['services'][$sid]['endpoints'][$eid] ??= [
                'id'               => $eid,
                'name'             => $row['ename'],
                'method'           => self::enumValue($row['emethod']),
                'url'              => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', $endpointParams),
                'request_examples' => [],
            ];

            $xid = $row['xid'];
            if ($xid === null) {
                continue;
            }
            $tree[$wid]['services'][$sid]['endpoints'][$eid]['request_examples'][] = [
                'id'   => $xid,
                'name' => $row['xname'],
                'url'  => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', $endpointParams + ['request_example' => $xid]),
            ];
        }

        if ($this->foldersEnabled && $this->folderRepository instanceof ApiFolderRepository) {
            $tree = $this->nestFolders($tree);
        }

        $out = [];
        foreach ($tree as $workspace) {
            $services = [];
            foreach ($workspace['services'] as $service) {
                $service['endpoints'] = array_values($service['endpoints']);
                $services[]           = $service;
            }
            $workspace['services'] = $services;
            $out[]                 = $workspace;
        }

        return $out;
    }

    /**
     * Moves each endpoint into its folder node (hidden folders and their endpoints are dropped) and marks the services
     * the user may reorganise (`workspace.edit`): their nodes carry the move URL for the drag & drop of the tree.
     *
     * @param array<int, array<string, mixed>> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    private function nestFolders(array $tree): array
    {
        $serviceIds = [];
        foreach ($tree as $workspace) {
            /** @var array<int, array<string, mixed>> $services */
            $services = $workspace['services'];
            foreach (array_keys($services) as $sid) {
                $serviceIds[] = (int) $sid;
            }
        }
        if ($serviceIds === [] || !$this->folderRepository instanceof ApiFolderRepository) {
            return $tree;
        }

        $rows   = $this->folderRepository->treeRows($serviceIds);
        $hidden = $this->folderAccess?->hiddenFolderIds($rows) ?? [];
        $byId   = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        $isHidden = static function (int $id) use ($hidden, $byId): bool {
            $seen = [];
            for ($node = $byId[$id] ?? null; $node !== null; $node = $node['parent'] !== null ? ($byId[$node['parent']] ?? null) : null) {
                if (isset($hidden[$node['id']]) || isset($seen[$node['id']])) {
                    return true;
                }
                $seen[$node['id']] = true;
            }

            return false;
        };
        $endpointFolders = $this->folderRepository->endpointFolderIds($serviceIds);

        foreach ($tree as $wid => $workspace) {
            $editable = $this->canEdit((int) $wid);
            /** @var array<int, array<string, mixed>> $services */
            $services = $workspace['services'];
            foreach ($services as $sid => $service) {
                /** @var array<int, array<string, mixed>> $endpoints */
                $endpoints = $service['endpoints'];
                $nodes     = [];
                foreach ($rows as $row) {
                    if ($row['service'] !== $sid || $isHidden($row['id'])) {
                        continue;
                    }
                    $nodes[$row['id']] = [
                        'id'        => $row['id'],
                        'name'      => $row['name'],
                        'parent'    => $row['parent'],
                        'url'       => $this->urlGenerator->generate('nowo_api_studio_folder_edit', ['workspaceId' => $wid, 'serviceId' => $sid, 'id' => $row['id']]),
                        'folders'   => [],
                        'endpoints' => [],
                    ];
                }

                $root = [];
                foreach ($endpoints as $eid => $endpoint) {
                    $folderId = $endpointFolders[$eid] ?? null;
                    if ($folderId === null) {
                        $root[] = $endpoint;
                    } elseif (isset($nodes[$folderId])) {
                        $nodes[$folderId]['endpoints'][] = $endpoint;
                    }
                }

                $service['endpoints'] = $root;
                $service['folders']   = self::nest($nodes);
                $service['move_url']  = $editable
                    ? $this->urlGenerator->generate('nowo_api_studio_folder_tree_move', ['workspaceId' => $wid, 'serviceId' => $sid])
                    : null;
                $services[$sid] = $service;
            }
            $tree[$wid]['services'] = $services;
        }

        return $tree;
    }

    /**
     * Nested folder nodes from a flat id-keyed list (rows are in tree order; children keep it).
     *
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function nest(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $id => $node) {
            $parent              = is_int($node['parent']) && isset($nodes[$node['parent']]) ? $node['parent'] : 0;
            $children[$parent][] = $id;
        }

        // Built from the root down: a corrupt cycle (never stored, moves refuse it) is simply not reachable.
        $build = static function (int $parent) use (&$build, $children, $nodes): array {
            $out = [];
            foreach ($children[$parent] ?? [] as $id) {
                $node            = $nodes[$id];
                $node['folders'] = $build($id);
                unset($node['parent']);
                $out[] = $node;
            }

            return $out;
        };

        return $build(0);
    }

    private function canEdit(int $workspaceId): bool
    {
        if (!$this->authorization instanceof ApiStudioAuthorization) {
            return true;
        }
        $workspace = $this->workspaceRepository->find($workspaceId);

        return $workspace instanceof ApiWorkspace && $this->authorization->can(ApiStudioAction::WorkspaceEdit, $workspace);
    }

    private static function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
