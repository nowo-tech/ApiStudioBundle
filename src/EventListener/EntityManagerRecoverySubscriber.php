<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_keys;
use function is_string;
use function str_starts_with;

/**
 * Resets the Api Studio entity manager at the start of an Api Studio request when a previous
 * request on the same worker closed it (failed flush) and nothing reset it in between.
 */
final readonly class EntityManagerRecoverySubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_api_studio_';

    public function __construct(
        private ManagerRegistry $registry,
    ) {
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

        $manager = $this->registry->getManagerForClass(ApiWorkspace::class);
        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            return;
        }

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) === $manager) {
                $this->registry->resetManager($name);

                return;
            }
        }
    }
}
