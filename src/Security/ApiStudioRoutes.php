<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use InvalidArgumentException;

use function in_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Which routes are Api Studio routes (1.16): the bundle's own (`nowo_api_studio_*`, actions in
 * {@see ApiStudioRouteActions}) plus the host pages declared in `security.host_routes` (e.g. a sharing page) with the
 * tenant-level action they need. Every listed route goes through the same chain as the bundle's: enabled check,
 * tenant, roles, access checker, `studio.open`, feature gate, its action and the 403 / 404 policy.
 *
 * Host routes only declare `studio.open` or `workspace.create`: the workspace of a host page is the host's to load, so
 * its controller calls {@see ApiStudioAuthorization::requireWorkspace()} with the workspace action it needs.
 */
final readonly class ApiStudioRoutes
{
    public const BUNDLE_PREFIX = 'nowo_api_studio_';

    /** The actions a host route may declare (tenant-level). */
    public const HOST_ROUTE_ACTIONS = [ApiStudioAction::StudioOpen, ApiStudioAction::WorkspaceCreate];

    /** @var array<string, ApiStudioAction> */
    private array $hostRoutes;

    /**
     * @param array<string, ApiStudioAction|string> $hostRoutes route name => tenant-level action
     */
    public function __construct(array $hostRoutes = [])
    {
        $routes = [];
        foreach ($hostRoutes as $route => $action) {
            $action = $action instanceof ApiStudioAction ? $action : ApiStudioAction::tryFrom($action);
            if ($route === '' || str_starts_with($route, self::BUNDLE_PREFIX)) {
                throw new InvalidArgumentException(sprintf('Api Studio host route "%s": use a route of the host (the bundle\'s own "%s*" routes are mapped by the bundle).', $route, self::BUNDLE_PREFIX));
            }
            if (!in_array($action, self::HOST_ROUTE_ACTIONS, true)) {
                throw new InvalidArgumentException(sprintf('Api Studio host route "%s": the action must be "studio.open" or "workspace.create"; check workspace actions in the controller with ApiStudioAuthorization::requireWorkspace().', $route));
            }
            $routes[$route] = $action;
        }
        $this->hostRoutes = $routes;
    }

    public function isStudioRoute(mixed $route): bool
    {
        return is_string($route) && (str_starts_with($route, self::BUNDLE_PREFIX) || isset($this->hostRoutes[$route]));
    }

    /**
     * The action a route needs; null for a route nobody mapped (the access subscriber answers 404: deny by default).
     */
    public function actionFor(string $route): ?ApiStudioAction
    {
        return ApiStudioRouteActions::actionFor($route) ?? $this->hostRoutes[$route] ?? null;
    }

    /**
     * @return array<string, ApiStudioAction>
     */
    public function hostRoutes(): array
    {
        return $this->hostRoutes;
    }
}
