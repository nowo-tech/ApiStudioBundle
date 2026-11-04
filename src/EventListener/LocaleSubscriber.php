<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Nowo\ApiStudioBundle\Service\LocaleManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Applies the stored API Studio UI locale to requests, per `nowo_api_studio.ui.locale_scope`:
 *
 * - `routes` (default since 1.11): only Api Studio routes (`nowo_api_studio_*`); the host's own pages keep their locale;
 * - `global` (1.10 behaviour): every main request of the host;
 * - `none`: never (the host owns the locale; {@see LocaleManager::resolveLocale()} stays available).
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ROUTES = 'routes';
    public const SCOPE_NONE   = 'none';
    public const SCOPES       = [self::SCOPE_GLOBAL, self::SCOPE_ROUTES, self::SCOPE_NONE];

    private const ROUTE_PREFIX = 'nowo_api_studio_';

    public function __construct(
        private readonly LocaleManager $localeManager,
        private readonly string $scope = self::SCOPE_GLOBAL,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->scope === self::SCOPE_NONE) {
            return;
        }

        // Runs after the router (32): the route is known.
        $route = $event->getRequest()->attributes->get('_route');
        if ($this->scope === self::SCOPE_ROUTES && (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX))) {
            return;
        }

        $event->getRequest()->setLocale($this->localeManager->resolveLocale());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }
}
