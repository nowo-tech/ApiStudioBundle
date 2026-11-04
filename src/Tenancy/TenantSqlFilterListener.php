<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * With `tenancy.enabled` and `tenancy.sql_filter` (default true): enables {@see TenantSqlFilter} with the request's
 * scope once the access checks passed (controller event, after the bundle's access subscriber), and disables it when
 * the request finishes (worker mode).
 */
final readonly class TenantSqlFilterListener implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_api_studio_';

    public function __construct(
        private ManagerRegistry $registry,
        private ApiStudioAuthorization $authorization,
        private bool $enabled = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER     => ['onKernelController', -8],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!$this->enabled || !$event->isMainRequest() || !is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
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

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
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
        $manager = $this->registry->getManagerForClass(ApiWorkspace::class);

        return $manager instanceof EntityManagerInterface ? $manager : null;
    }
}
