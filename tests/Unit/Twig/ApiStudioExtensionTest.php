<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Twig;

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
        $repository->expects(self::never())->method('navigationRows');

        $globals = $this->extension($repository)->getGlobals();

        self::assertArrayNotHasKey('nowo_api_studio_nav_tree', $globals);
        self::assertSame(['en', 'es'], $globals['nowo_api_studio_locales']);
        self::assertSame('@Layout/base.html.twig', $globals[ApiStudioExtension::GLOBAL_LAYOUT_TEMPLATE]);
        self::assertSame('bootstrap', $globals[ApiStudioExtension::GLOBAL_CSS_FRAMEWORK]);
    }

    public function testNavigationTreeIsRebuiltOnEachRenderWithoutReset(): void
    {
        $repository = $this->createMock(ApiWorkspaceRepository::class);
        $row        = static fn (int $id, string $name): array => [
            'wid' => $id, 'wname' => $name, 'wslug' => strtolower($name), 'sid' => null, 'sname' => null, 'sprotocol' => null,
            'eid' => null, 'ename' => null, 'emethod' => null, 'xid' => null, 'xname' => null,
        ];
        $repository->method('navigationRows')->willReturnOnConsecutiveCalls(
            [$row(1, 'First')],
            [$row(1, 'First'), $row(2, 'Second')],
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

        self::assertSame(['api_studio_method_class', 'api_studio_var', 'api_studio_nav_tree', 'nowo_api_studio_scripts_enabled', 'nowo_api_studio_scripts_confirm_stored', 'nowo_api_studio_scripts_mode', 'nowo_api_studio_scripts_timeout_ms', 'nowo_api_studio_can', 'nowo_api_studio_folders_enabled', 'nowo_api_studio_visible_endpoints'], $names);
        self::assertSame('as-method-get', $extension->methodClass('get'));
        self::assertSame('as-method-default', $extension->methodClass('OPTIONS'));
        self::assertSame('as-method-post', $extension->methodClass('post'));
        self::assertSame('as-method-put', $extension->methodClass('PUT'));
        self::assertSame('as-method-patch', $extension->methodClass('patch'));
        self::assertSame('as-method-delete', $extension->methodClass('DELETE'));
        self::assertSame('as-method-soap', $extension->methodClass('soap'));
        self::assertSame('{{token}}', $extension->formatVariable('token'));
    }

    public function testStoredScriptsAreConfirmedByDefault(): void
    {
        $extension = $this->extension($this->createMock(ApiWorkspaceRepository::class));

        self::assertTrue($extension->scriptsEnabled());
        self::assertTrue($extension->scriptsConfirmStored());
    }

    public function testConfirmStoredCanBeTurnedOff(): void
    {
        self::assertFalse($this->scriptsExtension(ApiStudioExtension::SCRIPTS_INLINE, false)->scriptsConfirmStored());
        self::assertTrue($this->scriptsExtension(ApiStudioExtension::SCRIPTS_INLINE, true)->scriptsConfirmStored());
    }

    public function testSandboxModeNeedsNoConfirmation(): void
    {
        $extension = $this->scriptsExtension(ApiStudioExtension::SCRIPTS_SANDBOX, true);

        self::assertTrue($extension->scriptsEnabled());
        self::assertFalse($extension->scriptsConfirmStored());
        self::assertSame('sandbox', $extension->scriptsMode());
        self::assertSame(2000, $extension->scriptsTimeoutMs());
    }

    public function testNothingToConfirmWhenScriptsAreDisabled(): void
    {
        $extension = $this->scriptsExtension(ApiStudioExtension::SCRIPTS_DISABLED, true);

        self::assertFalse($extension->scriptsEnabled());
        self::assertFalse($extension->scriptsConfirmStored());
    }

    public function testLayoutAndCssFrameworkGlobalsDefaultToNull(): void
    {
        $extension = new ApiStudioExtension(
            new LocaleManager(new RequestStack(), ['en'], 'en'),
            new StudioNavigationProvider($this->createMock(ApiWorkspaceRepository::class), $this->createMock(UrlGeneratorInterface::class)),
        );

        self::assertNull($extension->getGlobals()[ApiStudioExtension::GLOBAL_LAYOUT_TEMPLATE]);
        self::assertNull($extension->getGlobals()[ApiStudioExtension::GLOBAL_CSS_FRAMEWORK]);
    }

    private function scriptsExtension(string $mode, bool $confirmStored): ApiStudioExtension
    {
        return new ApiStudioExtension(
            new LocaleManager(new RequestStack(), ['en'], 'en'),
            new StudioNavigationProvider($this->createMock(ApiWorkspaceRepository::class), $this->createMock(UrlGeneratorInterface::class)),
            scriptsMode: $mode,
            scriptsConfirmStored: $confirmStored,
        );
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
