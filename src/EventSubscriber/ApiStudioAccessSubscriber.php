<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventSubscriber;

use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_string;
use function sprintf;

/**
 * Guards all Api Studio UI routes ({@code nowo_api_studio_*}).
 *
 * - Bundle disabled ({@code nowo_api_studio.enabled: false}): every route answers 404.
 * - Requires at least one of {@code $requiredRoles} (skipped when the list is empty or SecurityBundle is absent).
 * - Calls the configured {@see ApiStudioAccessCheckerInterface} ({@code security.access_checker}) with the current
 *   user; anonymous requests are denied unless {@code $allowUnauthenticated} is true.
 */
final readonly class ApiStudioAccessSubscriber implements EventSubscriberInterface
{
    public const ROUTE_PREFIX = 'nowo_api_studio_';

    /**
     * @param list<string> $requiredRoles
     */
    public function __construct(
        private array $requiredRoles,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private bool $enabled = true,
        private ?ApiStudioAccessCheckerInterface $accessChecker = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Right after the router (priority 32): a disabled UI is unreachable before other listeners run.
            KernelEvents::REQUEST    => ['onKernelRequest', 30],
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public static function isApiStudioRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled && self::isApiStudioRoute($event->getRequest())) {
            throw new NotFoundHttpException('Api Studio is disabled.');
        }
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!self::isApiStudioRoute($event->getRequest())) {
            return;
        }

        if (!$this->enabled) {
            throw new NotFoundHttpException('Api Studio is disabled.');
        }

        $this->checkRoles();
        $this->checkAccessChecker();
    }

    private function checkRoles(): void
    {
        if ($this->requiredRoles === [] || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return;
        }

        foreach ($this->requiredRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return;
            }
        }

        throw new AccessDeniedException(sprintf('Api Studio requires one of the following roles: %s.', implode(', ', $this->requiredRoles)));
    }

    private function checkAccessChecker(): void
    {
        if (!$this->accessChecker instanceof ApiStudioAccessCheckerInterface) {
            return;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        if ($user === null) {
            if ($this->allowUnauthenticated) {
                return;
            }

            throw new AccessDeniedException('Api Studio requires an authenticated user.');
        }

        if (!$this->accessChecker->canAccess($user)) {
            throw new AccessDeniedException('Access to Api Studio is denied.');
        }
    }
}
