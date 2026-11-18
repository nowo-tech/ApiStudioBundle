<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;

/**
 * With `scope.enabled` (1.17), resolves the host scope of every Api Studio main request once, right after the tenant
 * ({@see ApiStudioTenantListener}, priority 29), and stores it on the request ({@see ScopeContext::REQUEST_ATTRIBUTE}).
 * A route mounted under `scope.path` without a scope answers 404 (deny by default). The attribute is always written
 * (null included), so nothing of an earlier request can be read back in a worker.
 */
final readonly class ScopeListener implements EventSubscriberInterface
{
    public function __construct(
        private ?ScopeResolverInterface $resolver = null,
        private bool $enabled = false,
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
        private ?ApiStudioTenantContext $tenantContext = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 28]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !$this->routes->isStudioRoute($route)) {
            return;
        }

        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, null);
        $scoped = ApiStudioRoutes::isScopedMount($route);
        if (!$this->enabled) {
            if ($scoped) {
                throw new NotFoundHttpException('Api Studio: workspace scopes are disabled.');
            }

            return;
        }

        $tenant = $this->tenantContext?->current() ?? ApiStudioTenant::instance();
        $scope  = $this->resolver?->resolve(ScopeRouteContext::fromAttributes($route, $request->attributes->all(), $scoped), $tenant);
        if ($scoped && !$scope instanceof WorkspaceScope) {
            throw new NotFoundHttpException('Api Studio: no scope for this request.');
        }

        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, $scope);
    }
}
