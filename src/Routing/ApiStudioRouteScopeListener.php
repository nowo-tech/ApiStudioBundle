<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Routing;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContextAwareInterface;

use function is_array;
use function is_scalar;
use function is_string;
use function preg_match_all;
use function str_starts_with;

/**
 * `ui.path` placeholders (e.g. `/organizations/{organization}/api-studio`): on an Api Studio request the values of the
 * matched route become defaults of the router context, so every link the bundle generates (`generateUrl()`,
 * `redirectToRoute()`, Twig `path()`) carries them without passing them. The previous context values are restored
 * when the request finishes (sub-requests, worker mode). Outside Api Studio requests nothing is set: the host passes
 * the parameters itself.
 */
final readonly class ApiStudioRouteScopeListener implements EventSubscriberInterface
{
    /** Route parameter names the bundle uses itself: not allowed as ui.path placeholders. */
    public const RESERVED_PARAMETERS = ['id', 'workspaceId', 'serviceId', 'endpointId', 'environmentId', 'exampleId', 'format', '_locale', '_format', '_controller', '_route'];

    private const ROUTE_PREFIX       = 'nowo_api_studio_';
    private const PREVIOUS_ATTRIBUTE = '_nowo_api_studio_route_scope_previous';

    /**
     * @param list<string> $placeholders the placeholders of ui.path
     */
    public function __construct(
        private RequestContextAwareInterface $router,
        private array $placeholders = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public static function placeholdersOf(string $path): array
    {
        preg_match_all('/\{(!?)([^}]+)\}/', $path, $matches);

        return $matches[2];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Right after the router (32): the route and its attributes are known.
            KernelEvents::REQUEST        => ['onKernelRequest', 31],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if ($this->placeholders === [] || !is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $context  = $this->router->getContext();
        $previous = [];
        foreach ($this->placeholders as $name) {
            $value = $request->attributes->get($name);
            if (!is_scalar($value)) {
                continue;
            }
            $previous[$name] = $context->hasParameter($name) ? $context->getParameter($name) : null;
            // @igor-ignore - Per-request router context value, restored on kernel.finish_request.
            $context->setParameter($name, (string) $value);
        }

        $request->attributes->set(self::PREVIOUS_ATTRIBUTE, $previous);
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        $this->restore($event->getRequest());
    }

    private function restore(Request $request): void
    {
        $previous = $request->attributes->get(self::PREVIOUS_ATTRIBUTE);
        if (!is_array($previous)) {
            return;
        }

        $context    = $this->router->getContext();
        $parameters = $context->getParameters();
        foreach ($previous as $name => $value) {
            if ($value === null) {
                unset($parameters[$name]);
            } else {
                $parameters[$name] = $value;
            }
        }
        // @igor-ignore - Restores the router context of the enclosing request.
        $context->setParameters($parameters);
        $request->attributes->remove(self::PREVIOUS_ATTRIBUTE);
    }
}
