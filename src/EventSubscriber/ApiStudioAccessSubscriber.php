<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function sprintf;

/**
 * Requires at least one configured role for all Api Studio UI routes.
 *
 * No-op when required_roles is empty or SecurityBundle is not installed.
 */
final readonly class ApiStudioAccessSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_api_studio_';

    /**
     * @param list<string> $requiredRoles
     */
    public function __construct(
        private array $requiredRoles,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if ($this->requiredRoles === [] || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || !str_starts_with((string) $route, self::ROUTE_PREFIX)) {
            return;
        }

        foreach ($this->requiredRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return;
            }
        }

        throw new AccessDeniedException(sprintf('Api Studio requires one of the following roles: %s.', implode(', ', $this->requiredRoles)));
    }
}
