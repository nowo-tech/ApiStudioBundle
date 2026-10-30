<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Routing;

use Nowo\ApiStudioBundle\Controller\DashboardController;
use Nowo\ApiStudioBundle\Tests\Integration\App\RoutingControllersKernel;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * The bundle routes are opt-in (the host imports routes.yaml with its prefix): the Symfony >= 7.3
 * `routing.controllers` resource must not load them through the controller services.
 */
final class RoutingControllersTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return RoutingControllersKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new RoutingControllersKernel('test', true);
    }

    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers needs Symfony 7.3+.');
        }

        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo_api_studio_routing_controllers');
    }

    public function testRoutingControllersLoadsNoApiStudioRoute(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertFalse(str_starts_with($name, 'nowo_api_studio'), 'Unexpected route ' . $name);
            self::assertFalse(str_starts_with((string) $route->getDefault('_controller'), 'Nowo\\ApiStudioBundle\\'), 'Unexpected controller on ' . $name);
        }
    }

    public function testControllersStayControllerServices(): void
    {
        self::bootKernel();

        $controller = self::getContainer()->get(DashboardController::class);
        self::assertInstanceOf(DashboardController::class, $controller);

        // AbstractController still receives its service locator (container.service_subscriber + #[Required] setter).
        $container = (new ReflectionProperty(AbstractController::class, 'container'))->getValue($controller);
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue($container->has('twig'));
    }
}
