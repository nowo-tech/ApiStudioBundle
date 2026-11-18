<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * With `tenancy.enabled` and `tenancy.sql_filter` (default true): enables {@see TenantSqlFilter} with the request's
 * scope once the access checks passed (controller event, after the bundle's access subscriber), and disables it when
 * the request finishes (worker mode). 1.16: also disabled when a main request starts, so a request that never finished
 * (fatal path in a long-lived worker) cannot leave it on for the next one.
 */
final readonly class TenantSqlFilterListener implements EventSubscriberInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private ApiStudioAuthorization $authorization,
        private bool $enabled = false,
        private ?string $entityManager = null,
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST        => ['onKernelRequest', 31],
            KernelEvents::CONTROLLER     => ['onKernelController', -8],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest() || !$this->routes->isStudioRoute($event->getRequest()->attributes->get('_route'))) {
            return;
        }

        $manager = $this->manager();
        if (!$manager instanceof EntityManagerInterface || $manager->getConfiguration()->getFilterClassName(TenantSqlFilter::NAME) === null) {
            return;
        }

        // @igor-ignore - The filter is per request: disabled again on kernel.finish_request.
        $filter = $manager->getFilters()->enable(TenantSqlFilter::NAME);
        if ($filter instanceof TenantSqlFilter) {
            TenantSqlFilter::configure($filter, $this->authorization->scope());
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->disable();
        }
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->disable();
        }
    }

    private function disable(): void
    {
        if (!$this->enabled) {
            return;
        }

        $manager = $this->manager();
        if ($manager instanceof EntityManagerInterface && $manager->getFilters()->isEnabled(TenantSqlFilter::NAME)) {
            // @igor-ignore - The filter is per request: off when the request finishes.
            $manager->getFilters()->disable(TenantSqlFilter::NAME);
        }
    }

    private function manager(): ?EntityManagerInterface
    {
        return (new ApiStudioEntityManagerResolver($this->registry, $this->entityManager))->find();
    }
}
