<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function count;
use function is_array;
use function is_bool;
use function is_object;
use function spl_object_id;
use function sprintf;

/**
 * Per-workspace, per-action decisions of the current request: tenant scope ({@see ApiStudioScope}) + the host's
 * {@see ApiStudioPermissionCheckerInterface} (default {@see LegacyPermissionChecker}: everything granted).
 *
 * - {@see self::requireWorkspace()}: unknown, other tenant, not visible or not `workspace.view` → 404; viewable but not
 *   granted the action → 403.
 * - {@see self::can()}: what pages ask to hide actions.
 *
 * Decisions and the scope are memoised on the main request (worker-safe: nothing is kept in the service).
 */
final readonly class ApiStudioAuthorization
{
    /** Largest `visibleWorkspaceIds()` list accepted; a host with more returns null and relies on the tenant. */
    public const MAX_VISIBLE_IDS = 10_000;

    private const SCOPE_ATTRIBUTE     = '_nowo_api_studio_scope';
    private const DECISIONS_ATTRIBUTE = '_nowo_api_studio_decisions';

    private ApiStudioPermissionCheckerInterface $checker;

    public function __construct(
        ?ApiStudioPermissionCheckerInterface $checker = null,
        private ?ApiStudioTenantContext $tenantContext = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
    ) {
        $this->checker = $checker ?? new LegacyPermissionChecker();
    }

    public function tenant(): ApiStudioTenant
    {
        return $this->tenantContext?->current() ?? ApiStudioTenant::instance();
    }

    public function scope(): ApiStudioScope
    {
        $request = $this->requestStack?->getMainRequest();
        $cached  = $request?->attributes->get(self::SCOPE_ATTRIBUTE);
        if ($cached instanceof ApiStudioScope) {
            return $cached;
        }

        $tenant = $this->tenant();
        $ids    = $this->checker->visibleWorkspaceIds($this->user(), $tenant);
        if (is_array($ids) && count($ids) > self::MAX_VISIBLE_IDS) {
            throw new LogicException(sprintf('Api Studio: visibleWorkspaceIds() returned more than %d ids; return null and let the tenant scope the lists.', self::MAX_VISIBLE_IDS));
        }

        $scope = new ApiStudioScope($tenant, $this->tenantContext?->isEnabled() ?? false, $ids);
        // @igor-ignore - Memoised on the request itself (request-scoped, not service state).
        $request?->attributes->set(self::SCOPE_ATTRIBUTE, $scope);

        return $scope;
    }

    /**
     * Whether the current user may do $action (on $workspace for workspace actions). A workspace outside the scope is
     * never granted anything.
     */
    public function can(ApiStudioAction $action, ?ApiWorkspace $workspace = null): bool
    {
        if ($action->needsWorkspace() && !$workspace instanceof ApiWorkspace) {
            return false;
        }

        if ($workspace instanceof ApiWorkspace && !$this->scope()->contains($workspace)) {
            return false;
        }

        $request   = $this->requestStack?->getMainRequest();
        $key       = $action->value . '#' . ($workspace instanceof ApiWorkspace ? ($workspace->getId() ?? 'new' . spl_object_id($workspace)) : '');
        $decisions = $request?->attributes->get(self::DECISIONS_ATTRIBUTE);
        $decisions = is_array($decisions) ? $decisions : [];
        if (is_bool($decisions[$key] ?? null)) {
            return $decisions[$key];
        }

        $granted         = $this->checker->isGranted($this->user(), $action, new ApiStudioSubject($this->tenant(), $workspace));
        $decisions[$key] = $granted;
        // @igor-ignore - Memoised on the request itself (request-scoped, not service state).
        $request?->attributes->set(self::DECISIONS_ATTRIBUTE, $decisions);

        return $granted;
    }

    /**
     * The workspace when the user may do $action on it: 404 when it is unknown, outside the scope or not viewable;
     * 403 when it is viewable but $action is not granted.
     */
    public function requireWorkspace(?ApiWorkspace $workspace, ApiStudioAction $action = ApiStudioAction::WorkspaceView): ApiWorkspace
    {
        if (!$workspace instanceof ApiWorkspace || !$this->can(ApiStudioAction::WorkspaceView, $workspace)) {
            throw new NotFoundHttpException('Workspace not found.');
        }

        if (!$this->can($action, $workspace)) {
            throw new AccessDeniedHttpException(sprintf('Api Studio: "%s" is not allowed on this workspace.', $action->value));
        }

        return $workspace;
    }

    /**
     * Tenant-level actions: `studio.open` denied → 404 (nothing to see), `workspace.create` denied → 403.
     */
    public function denyUnlessGranted(ApiStudioAction $action): void
    {
        if ($this->can($action)) {
            return;
        }

        if ($action === ApiStudioAction::StudioOpen) {
            throw new NotFoundHttpException('Api Studio not found.');
        }

        throw new AccessDeniedHttpException(sprintf('Api Studio: "%s" is not allowed.', $action->value));
    }

    /**
     * The signed-in user (null: anonymous).
     */
    public function user(): ?object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }
}
