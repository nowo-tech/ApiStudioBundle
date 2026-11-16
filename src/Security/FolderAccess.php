<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_slice;
use function is_array;
use function is_bool;
use function sprintf;

/**
 * Folder-level decisions (1.14): the host's {@see ApiStudioFolderPermissionCheckerInterface}, asked for each folder from
 * the top-level one down after the workspace decision. Without a folder checker every folder answers like its
 * workspace (nothing extra is asked).
 *
 * Decisions are memoised on the main request's attributes (request-scoped, nothing kept in the service: worker-safe).
 */
final readonly class FolderAccess
{
    private const DECISIONS_ATTRIBUTE = '_nowo_api_studio_folder_decisions';

    public function __construct(
        private ?ApiStudioFolderPermissionCheckerInterface $checker = null,
        private ?ApiStudioAuthorization $authorization = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /** Whether a host folder checker is configured (otherwise folders never narrow anything). */
    public function isActive(): bool
    {
        return $this->checker instanceof ApiStudioFolderPermissionCheckerInterface;
    }

    /**
     * Whether $action is granted on $folder: on the folder and every folder above it (view is required too).
     */
    public function can(ApiStudioAction $action, ApiFolder $folder): bool
    {
        if (!$this->checker instanceof ApiStudioFolderPermissionCheckerInterface) {
            return true;
        }

        $rows = [];
        foreach ($folder->getPath() as $node) {
            $rows[] = [
                'id'        => (int) $node->getId(),
                'service'   => (int) $node->getService()?->getId(),
                'workspace' => (int) $node->getService()?->getWorkspace()?->getId(),
                'createdBy' => $node->getCreatedBy(),
            ];
        }

        return $this->canPath($action, $rows);
    }

    /**
     * Same as {@see self::can()} for an endpoint: an endpoint at the service root has nothing to narrow.
     */
    public function canEndpoint(ApiStudioAction $action, ApiEndpoint $endpoint): bool
    {
        $folder = $endpoint->getFolder();

        return !$folder instanceof ApiFolder || $this->can($action, $folder);
    }

    /**
     * 404 when the folder is hidden for the user, 403 when it is visible but $action is not granted.
     */
    public function requireFolder(ApiFolder $folder, ApiStudioAction $action = ApiStudioAction::WorkspaceView): ApiFolder
    {
        if (!$this->can(ApiStudioAction::WorkspaceView, $folder)) {
            throw new NotFoundHttpException('Folder not found.');
        }
        if ($action !== ApiStudioAction::WorkspaceView && !$this->can($action, $folder)) {
            throw new AccessDeniedHttpException(sprintf('Api Studio: "%s" is not allowed in this folder.', $action->value));
        }

        return $folder;
    }

    /**
     * Same as {@see self::requireFolder()} for the folder of an endpoint (none: nothing to check).
     */
    public function requireEndpoint(ApiEndpoint $endpoint, ApiStudioAction $action = ApiStudioAction::WorkspaceView): ApiEndpoint
    {
        $folder = $endpoint->getFolder();
        if (!$folder instanceof ApiFolder) {
            return $endpoint;
        }

        try {
            $this->requireFolder($folder, $action);
        } catch (NotFoundHttpException) {
            throw new NotFoundHttpException('Endpoint not found.');
        }

        return $endpoint;
    }

    /**
     * Ids of the folders hidden from the user (view denied on them or on a folder above), from tree rows.
     *
     * @param list<array{id: int, parent: int|null, service: int, workspace: int, createdBy: string|null}> $rows
     *
     * @return array<int, true>
     */
    public function hiddenFolderIds(array $rows): array
    {
        if (!$this->checker instanceof ApiStudioFolderPermissionCheckerInterface || $rows === []) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        $hidden = [];
        foreach ($rows as $row) {
            $path = [];
            $seen = [];
            for ($node = $row; $node !== null; $node = $node['parent'] !== null ? ($byId[$node['parent']] ?? null) : null) {
                if (isset($seen[$node['id']])) {
                    break;
                }
                $seen[$node['id']] = true;
                array_unshift($path, $node);
            }
            if (!$this->canPath(ApiStudioAction::WorkspaceView, $path)) {
                $hidden[$row['id']] = true;
            }
        }

        return $hidden;
    }

    /**
     * @param list<array{id: int, service: int, workspace: int, createdBy: string|null}> $path top-level first
     */
    private function canPath(ApiStudioAction $action, array $path): bool
    {
        $actions = $action === ApiStudioAction::WorkspaceView ? [$action] : [ApiStudioAction::WorkspaceView, $action];
        foreach ($path as $index => $node) {
            $ancestors = [];
            foreach (array_slice($path, 0, $index) as $above) {
                $ancestors[] = $above['id'];
            }
            foreach ($actions as $asked) {
                if (!$this->decide($asked, $node, $ancestors)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array{id: int, service: int, workspace: int, createdBy: string|null} $node
     * @param list<int> $ancestors
     */
    private function decide(ApiStudioAction $action, array $node, array $ancestors): bool
    {
        $request   = $this->requestStack?->getMainRequest();
        $key       = $action->value . '#' . $node['id'];
        $decisions = $request?->attributes->get(self::DECISIONS_ATTRIBUTE);
        $decisions = is_array($decisions) ? $decisions : [];
        if (is_bool($decisions[$key] ?? null)) {
            return $decisions[$key];
        }

        $authorization = $this->authorization ?? new ApiStudioAuthorization();
        $granted       = $this->checker instanceof ApiStudioFolderPermissionCheckerInterface && $this->checker->isFolderGranted(
            $authorization->user(),
            $action,
            new ApiStudioFolderRef($node['id'], $node['service'], $node['workspace'], $ancestors, $node['createdBy'], $authorization->tenant()),
        );
        $decisions[$key] = $granted;
        // @igor-ignore - Memoised on the request itself (request-scoped, not service state).
        $request?->attributes->set(self::DECISIONS_ATTRIBUTE, $decisions);

        return $granted;
    }
}
