<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Routing;

use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The URL generator of the bundle's own links (1.17): on a request mounted under `scope.path` a bundle route
 * (`nowo_api_studio_*`) becomes its twin (`nowo_api_studio_scoped_*`), so every link stays inside the scope's prefix;
 * everywhere else it is the router as is. Used by the controllers, the page factory, the sidebar and the Twig
 * function `api_studio_path()`. Reads the current main request per call: nothing is kept (worker-safe).
 */
final readonly class ApiStudioUrlGenerator implements UrlGeneratorInterface
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private ?RequestStack $requestStack = null,
        private bool $scopedRoutes = false,
    ) {
    }

    /**
     * The route to generate for `$name` on the current request.
     */
    public function routeName(string $name): string
    {
        if (!$this->scopedRoutes || !ApiStudioRoutes::isScopedMount($this->requestStack?->getMainRequest()?->attributes->get('_route'))) {
            return $name;
        }

        return ApiStudioRoutes::scopedName($name);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return $this->router->generate($this->routeName($name), $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        $this->router->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->router->getContext();
    }
}
