<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Twig;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use Nowo\ApiStudioBundle\Twig\ApiStudioExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ApiStudioExtensionTest extends TestCase
{
    public function testNavigationTreeIsNotAGlobal(): void
    {
        $repository = $this->createMock(ApiWorkspaceRepository::class);
        $repository->expects(self::never())->method('findBy');

        $globals = $this->extension($repository)->getGlobals();

        self::assertArrayNotHasKey('nowo_api_studio_nav_tree', $globals);
        self::assertSame(['en', 'es'], $globals['nowo_api_studio_locales']);
        self::assertSame('@Layout/base.html.twig', $globals[ApiStudioExtension::GLOBAL_LAYOUT_TEMPLATE]);
        self::assertSame('bootstrap', $globals[ApiStudioExtension::GLOBAL_CSS_FRAMEWORK]);
    }

    public function testNavigationTreeIsRebuiltOnEachRenderWithoutReset(): void
    {
        $repository = $this->createMock(ApiWorkspaceRepository::class);
        $repository->method('findBy')->willReturnOnConsecutiveCalls(
            [new ApiWorkspace('First', 'first')],
            [new ApiWorkspace('First', 'first'), new ApiWorkspace('Second', 'second')],
        );

        $twig = new Environment(new ArrayLoader([
            'nav' => '{% for ws in api_studio_nav_tree() %}{{ ws.name }};{% endfor %}',
        ]));
        $twig->addExtension($this->extension($repository));

        // Same Environment instance, no resetGlobals() between the two "requests".
        self::assertSame('First;', $twig->render('nav'));
        self::assertSame('First;Second;', $twig->render('nav'));
    }

    public function testFunctionsAreRegistered(): void
    {
        $extension = $this->extension($this->createMock(ApiWorkspaceRepository::class));
        $names     = array_map(static fn ($function): string => $function->getName(), $extension->getFunctions());

        self::assertSame(['api_studio_method_class', 'api_studio_var', 'api_studio_nav_tree'], $names);
        self::assertSame('as-method-get', $extension->methodClass('get'));
        self::assertSame('as-method-default', $extension->methodClass('OPTIONS'));
    }

    private function extension(ApiWorkspaceRepository $repository): ApiStudioExtension
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/api-studio/x');

        return new ApiStudioExtension(
            new LocaleManager(new RequestStack(), ['en', 'es'], 'en'),
            new StudioNavigationProvider($repository, $urlGenerator),
            '@Layout/base.html.twig',
            'bootstrap',
        );
    }
}
