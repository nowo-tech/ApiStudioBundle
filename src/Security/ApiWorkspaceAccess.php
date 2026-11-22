<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use LogicException;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceQueryInterface;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceView;
use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Scope\ScopeAccessSubject;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.19 (DDD-host checklist B1/B8): {@see ApiWorkspaceAccessInterface} on {@see ApiWorkspaceQueryInterface} and the
 * host's checkers. Stateless: the workspace is read and the checkers are asked at every call (worker mode).
 *
 * @internal Hosts depend on the interface
 */
final readonly class ApiWorkspaceAccess implements ApiWorkspaceAccessInterface
{
    /** Shape of an opaque actor id (the one {@see ApiStudioHistoryScope} enforces on host resolvers). */
    private const ACTOR_PATTERN = '/^[\x21-\x7E]{1,64}$/D';

    private ApiStudioPermissionCheckerInterface $checker;

    public function __construct(
        private ApiWorkspaceQueryInterface $workspaces,
        ?ApiStudioPermissionCheckerInterface $checker = null,
        private ?ScopeAccessCheckerInterface $scopeChecker = null,
        private ApiStudioHistoryScope $actors = new ApiStudioHistoryScope(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->checker = $checker ?? new LegacyPermissionChecker();
    }

    public function isGranted(object|string|null $user, ApiStudioAction $action, ApiStudioTenant $tenant, int $workspaceId): bool
    {
        return $this->reachable($user, $action, $tenant, $workspaceId) instanceof ApiWorkspaceView;
    }

    public function viewableWorkspace(object|string|null $user, ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView
    {
        return $this->reachable($user, ApiStudioAction::WorkspaceView, $tenant, $workspaceId);
    }

    /**
     * The workspace when $user may do $action on it, else null. Any exception (store, actor resolver, host checker) is
     * logged with its class only and answered as the same refusal.
     */
    private function reachable(object|string|null $user, ApiStudioAction $action, ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView
    {
        if (!$action->needsWorkspace() || $workspaceId < 1) {
            return null;
        }

        try {
            $object  = is_string($user) ? null : $user;
            $actorId = is_string($user) ? $user : $this->actors->actorId($user);
            if (is_string($user) && preg_match(self::ACTOR_PATTERN, $user) !== 1) {
                return null;
            }

            $view = $this->workspaces->get($tenant, $workspaceId);
            // Exact-key rule: the port compared the id and the tenant; compared again here, whatever the port.
            if (!$view instanceof ApiWorkspaceView || $view->id !== $workspaceId || !$view->tenant->equals($tenant)) {
                return null;
            }

            if (!is_string($user) && !$this->listed($object, $tenant, $workspaceId)) {
                return null;
            }

            $scope = $view->scope === null ? null : new WorkspaceScope($view->scope);
            foreach ($action === ApiStudioAction::WorkspaceView ? [$action] : [ApiStudioAction::WorkspaceView, $action] as $step) {
                if (!$this->grants($object, $actorId, $step, $tenant, $workspaceId, $scope)) {
                    return null;
                }
            }

            return $view;
        } catch (Throwable $exception) {
            $this->logger->warning('Api Studio workspace access check failed; answered as a refusal.', ['bundle' => 'api_studio', 'action' => $action->value, 'exception' => $exception::class]);

            return null;
        }
    }

    /**
     * `visibleWorkspaceIds()` of the permission checker, as the studio's lists apply it (null: every workspace).
     */
    private function listed(?object $user, ApiStudioTenant $tenant, int $workspaceId): bool
    {
        $ids = $this->checker->visibleWorkspaceIds($user, $tenant);
        if (is_array($ids) && count($ids) > ApiStudioAuthorization::MAX_VISIBLE_IDS) {
            throw new LogicException(sprintf('Api Studio: visibleWorkspaceIds() returned more than %d ids.', ApiStudioAuthorization::MAX_VISIBLE_IDS));
        }

        return $ids === null || in_array($workspaceId, $ids, true);
    }

    private function grants(?object $user, ?string $actorId, ApiStudioAction $action, ApiStudioTenant $tenant, int $workspaceId, ?WorkspaceScope $scope): bool
    {
        // Deny by default: a scoped workspace needs the host's scope checker on top of the permission checker.
        if ($scope instanceof WorkspaceScope && (!$this->scopeChecker instanceof ScopeAccessCheckerInterface || !$this->scopeChecker->isGranted($user, $action, new ScopeAccessSubject($tenant, $scope, $workspaceId, $actorId)))) {
            return false;
        }

        return $this->checker->isGranted($user, $action, ApiStudioSubject::forWorkspaceId($tenant, $workspaceId, $scope, $actorId));
    }
}
