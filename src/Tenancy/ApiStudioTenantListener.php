<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * With tenancy on, resolves the tenant of every Api Studio main request (`nowo_api_studio_*` and the host's
 * `security.host_routes`, {@see ApiStudioRoutes}) once, right after the
 * router and the bundle's enabled check, and stores it on the request. No tenant: 404 (deny by default). Sub-requests
 * use the main request's tenant ({@see ApiStudioTenantContext::current()}).
 */
final readonly class ApiStudioTenantListener implements EventSubscriberInterface
{
    public function __construct(
        private ?ApiStudioTenantResolverInterface $resolver = null,
        private bool $enabled = false,
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 29]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->routes->isStudioRoute($request->attributes->get('_route'))) {
            return;
        }

        $tenant = $this->resolver?->resolve($request);
        if (!$tenant instanceof ApiStudioTenant) {
            throw new NotFoundHttpException('Api Studio: no tenant for this request.');
        }

        $request->attributes->set(ApiStudioTenantContext::REQUEST_ATTRIBUTE, $tenant);
    }
}
