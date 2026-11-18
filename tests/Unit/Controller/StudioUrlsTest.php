<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Tests\Support\StudioUrlsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * 1.17: controller links go through ApiStudioUrlGenerator (twins on a scoped mount), else through the router.
 */
final class StudioUrlsTest extends TestCase
{
    public function testWithTheStudioGeneratorLinksFollowTheMount(): void
    {
        $router    = $this->router();
        $container = new Container();
        $container->set('router', $router);
        $container->set(ApiStudioUrlGenerator::class, new ApiStudioUrlGenerator($router, new RequestStack([new Request(attributes: ['_route' => 'nowo_api_studio_scoped_dashboard'])]), true));

        $controller = $this->controller($container);
        self::assertSame('/nowo_api_studio_scoped_workspace_show', $controller->url('nowo_api_studio_workspace_show'));
        self::assertSame('nowo_api_studio_scoped_workspace_index', $controller->route('nowo_api_studio_workspace_index'));
    }

    public function testWithoutItTheRouterIsUsed(): void
    {
        $container = new Container();
        $container->set('router', $this->router());

        $controller = $this->controller($container);
        self::assertSame('/nowo_api_studio_workspace_show', $controller->url('nowo_api_studio_workspace_show'));
        self::assertSame('nowo_api_studio_workspace_index', $controller->route('nowo_api_studio_workspace_index'));
    }

    private function controller(Container $container): StudioUrlsController
    {
        $controller = new StudioUrlsController();
        $controller->setContainer($container);

        return $controller;
    }

    private function router(): UrlGeneratorInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/' . $name);

        return $router;
    }
}
