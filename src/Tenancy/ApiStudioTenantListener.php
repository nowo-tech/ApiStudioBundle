<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * With tenancy on, resolves the tenant of every Api Studio main request (`nowo_api_studio_*`) once, right after the
 * router and the bundle's enabled check, and stores it on the request. No tenant: 404 (deny by default). Sub-requests
 * use the main request's tenant ({@see ApiStudioTenantContext::current()}).
 */
final readonly class ApiStudioTenantListener implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_api_studio_';

    public function __construct(
        private ?ApiStudioTenantResolverInterface $resolver = null,
        private bool $enabled = false,
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
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $tenant = $this->resolver?->resolve($request);
        if (!$tenant instanceof ApiStudioTenant) {
            throw new NotFoundHttpException('Api Studio: no tenant for this request.');
        }

        $request->attributes->set(ApiStudioTenantContext::REQUEST_ATTRIBUTE, $tenant);
    }
}
