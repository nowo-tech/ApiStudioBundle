<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Routing;

use LogicException;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\RouterInterface;

use function array_diff;
use function array_keys;
use function implode;
use function sprintf;

/**
 * 1.16 (audit SY-L1): every `security.host_routes` key must name a route of the router. A typo would leave the host
 * page unguarded without any error, so the cache warm-up (container build, `cache:warmup`) fails instead. Not
 * optional: it runs whenever the kernel builds its cache.
 */
final readonly class HostRoutesCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private RouterInterface $router,
        private ApiStudioRoutes $routes,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $declared = array_keys($this->routes->hostRoutes());
        if ($declared === []) {
            return [];
        }

        $missing = array_diff($declared, array_keys($this->router->getRouteCollection()->all()));
        if ($missing !== []) {
            throw new LogicException(sprintf('nowo_api_studio.security.host_routes names routes that do not exist: "%s". Fix the names (they are compared as written) or remove them.', implode('", "', $missing)));
        }

        return [];
    }
}
