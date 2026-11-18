<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Routing;

use LogicException;
use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Routing\HostRoutesCacheWarmer;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * 1.16 (audit SY-L1): host route names are kept as written and must exist, or the cache warm-up fails.
 */
final class HostRoutesCacheWarmerTest extends TestCase
{
    public function testDashedRouteNamesAreKeptAsWritten(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'prod');
        (new ApiStudioExtension())->load([['security' => ['host_routes' => ['app-share' => 'studio.open', 'app_import' => 'workspace.create']]]], $container);

        $hostRoutes = $container->getDefinition(ApiStudioRoutes::class)->getArgument('$hostRoutes');
        self::assertSame(['app-share' => 'studio.open', 'app_import' => 'workspace.create'], $hostRoutes);
        self::assertTrue((new ApiStudioRoutes($hostRoutes))->isStudioRoute('app-share'), 'the dashed route is guarded');
        self::assertFalse((new ApiStudioRoutes($hostRoutes))->isStudioRoute('app_share'));
    }

    public function testDeclaredRoutesMustExist(): void
    {
        $routes = new RouteCollection();
        $routes->add('app-share', new Route('/share'));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);

        self::assertFalse((new HostRoutesCacheWarmer($router, new ApiStudioRoutes()))->isOptional());
        self::assertSame([], (new HostRoutesCacheWarmer($router, new ApiStudioRoutes()))->warmUp('/tmp'), 'nothing declared');
        self::assertSame([], (new HostRoutesCacheWarmer($router, new ApiStudioRoutes(['app-share' => 'studio.open'])))->warmUp('/tmp'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app_share", "app_gone"');
        (new HostRoutesCacheWarmer($router, new ApiStudioRoutes(['app-share' => 'studio.open', 'app_share' => 'studio.open', 'app_gone' => 'studio.open'])))->warmUp('/tmp');
    }
}
