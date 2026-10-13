<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Nowo\ApiStudioBundle\Controller\LocaleController;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LocaleControllerTest extends TestCase
{
    public function testRedirectsToLocalReferer(): void
    {
        self::assertSame('/api-studio/endpoints', $this->switchWithReferer('/api-studio/endpoints'));
    }

    public function testRedirectsToSameHostAbsoluteReferer(): void
    {
        self::assertSame('https://APP.test/api-studio?x=1', $this->switchWithReferer('https://APP.test/api-studio?x=1'));
    }

    public function testFallsBackToDashboardWithoutReferer(): void
    {
        self::assertSame('/generated/nowo_api_studio_dashboard', $this->switchWithReferer(null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeRefererProvider(): iterable
    {
        yield 'foreign host' => ['https://evil.example/phish'];
        yield 'host suffix' => ['https://app.test.evil.example/'];
        yield 'scheme-relative' => ['//evil.example/phish'];
        yield 'backslash' => ['/\\evil.example/phish'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'relative without slash' => ['evil.example'];
        yield 'control char' => ["/api-studio\r\nX-Injected: 1"];
        yield 'userinfo trick' => ['https://app.test@evil.example/'];
        yield 'malformed' => ['http:///'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafeRefererProvider')]
    public function testRejectsOpenRedirectReferer(string $referer): void
    {
        self::assertSame('/generated/nowo_api_studio_dashboard', $this->switchWithReferer($referer));
    }

    public function testUnknownLocaleIs404(): void
    {
        $request = Request::create('http://app.test/api-studio/locale/xx');

        $this->expectException(NotFoundHttpException::class);

        $this->controller($request)->switch('xx', $request);
    }

    private function switchWithReferer(?string $referer): ?string
    {
        $server  = $referer === null ? [] : ['HTTP_REFERER' => $referer];
        $request = Request::create('http://app.test/api-studio/locale/es', 'GET', [], [], [], $server);

        return $this->controller($request)->switch('es', $request)->headers->get('Location');
    }

    private function controller(Request $request): LocaleController
    {
        $request->setSession(new Session(new MockArraySessionStorage()));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/generated/' . $name);

        $container = new Container();
        $container->set('router', $router);

        $controller = new LocaleController(new LocaleManager(new RequestStack([$request]), ['en', 'es'], 'en'));
        $controller->setContainer($container);

        return $controller;
    }
}
