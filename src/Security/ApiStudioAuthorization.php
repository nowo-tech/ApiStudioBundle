<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Scope\ScopeAccessSubject;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
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
 * 1.17 (`scope.enabled`): the host scope narrows {@see ApiStudioScope}, and every action in a scope (a scoped request,
 * or a scoped workspace opened from the tenant level) also needs {@see ScopeAccessCheckerInterface} (deny by default).
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
        private ?ScopeContext $scopeContext = null,
        private ?ScopeAccessCheckerInterface $scopeChecker = null,
        private bool $tenantListAll = true,
    ) {
        $this->checker = $checker ?? new LegacyPermissionChecker();
    }

    public function tenant(): ApiStudioTenant
    {
        return $this->tenantContext?->current() ?? ApiStudioTenant::instance();
    }

    /**
     * 1.17: the host scope of the work being done (null: tenant level, or scopes off).
     */
    public function hostScope(): ?WorkspaceScope
    {
        return $this->scopeContext?->current();
    }

    public function scope(): ApiStudioScope
    {
        $request = $this->requestStack?->getMainRequest();
        $cached  = $request?->attributes->get(self::SCOPE_ATTRIBUTE);
        // A host scope switched by ScopeContext::runAs() inside the request is not served the memo of another scope.
        if ($cached instanceof ApiStudioScope && WorkspaceScope::key($cached->hostScope) === WorkspaceScope::key($this->hostScope())) {
            return $cached;
        }

        $tenant = $this->tenant();
        $ids    = $this->checker->visibleWorkspaceIds($this->user(), $tenant);
        if (is_array($ids) && count($ids) > self::MAX_VISIBLE_IDS) {
            throw new LogicException(sprintf('Api Studio: visibleWorkspaceIds() returned more than %d ids; return null and let the tenant scope the lists.', self::MAX_VISIBLE_IDS));
        }

        $scopes        = $this->scopeContext?->isEnabled() ?? false;
        $hostScope     = $scopes ? $this->hostScope() : null;
        $visibleScopes = null;
        if ($scopes && !$hostScope instanceof WorkspaceScope && $this->tenantListAll) {
            // Deny by default: without a scope checker no scoped workspace is listed at tenant level.
            $visibleScopes = $this->scopeChecker instanceof ScopeAccessCheckerInterface ? $this->scopeChecker->visibleScopes($this->user(), $tenant) : [];
            if (is_array($visibleScopes) && count($visibleScopes) > self::MAX_VISIBLE_IDS) {
                throw new LogicException(sprintf('Api Studio: visibleScopes() returned more than %d scopes; return null and let the access checks decide.', self::MAX_VISIBLE_IDS));
            }
        }

        $scope = new ApiStudioScope($tenant, $this->tenantContext?->isEnabled() ?? false, $ids, $hostScope, $scopes, $this->tenantListAll, $visibleScopes);
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

        // 1.17: the scope the action applies to — the workspace's own, else the request's (tenant-level actions).
        $actionScope = null;
        if ($this->scopeContext?->isEnabled() === true) {
            $actionScope = $workspace instanceof ApiWorkspace ? $workspace->getScope() : $this->hostScope();
        }

        $request   = $this->requestStack?->getMainRequest();
        $key       = $action->value . '#' . ($workspace instanceof ApiWorkspace ? ($workspace->getId() ?? 'new' . spl_object_id($workspace)) : '') . '@' . WorkspaceScope::key($actionScope);
        $decisions = $request?->attributes->get(self::DECISIONS_ATTRIBUTE);
        $decisions = is_array($decisions) ? $decisions : [];
        if (is_bool($decisions[$key] ?? null)) {
            return $decisions[$key];
        }

        $granted = true;
        if ($actionScope instanceof WorkspaceScope) {
            // Deny by default: a scoped action needs the host's scope checker to grant it, on top of the permission checker.
            $granted = $this->scopeChecker instanceof ScopeAccessCheckerInterface
                && $this->scopeChecker->isGranted($this->user(), $action, new ScopeAccessSubject($this->tenant(), $actionScope, $workspace?->getId()));
        }
        $granted         = $granted && $this->checker->isGranted($this->user(), $action, new ApiStudioSubject($this->tenant(), $workspace, $actionScope));
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
