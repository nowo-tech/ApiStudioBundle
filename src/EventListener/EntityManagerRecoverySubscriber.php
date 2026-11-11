<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Resets the Api Studio entity manager ({@see ApiStudioEntityManagerResolver}: `nowo_api_studio.entity_manager`, else
 * the manager of the entities) at the start of an Api Studio request when a previous request on the same worker
 * closed it (failed flush) and nothing reset it in between. Never resets another manager.
 */
final readonly class EntityManagerRecoverySubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_api_studio_';

    private ApiStudioEntityManagerResolver $entityManagers;

    public function __construct(
        ManagerRegistry $registry,
        ?string $entityManager = null,
    ) {
        $this->entityManagers = new ApiStudioEntityManagerResolver($registry, $entityManager);
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) so `_route` is known, before the firewall (8).
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $this->entityManagers->resetIfClosed();
    }
}
