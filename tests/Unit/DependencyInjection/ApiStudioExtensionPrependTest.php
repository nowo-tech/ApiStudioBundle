<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * prepend(): FormKit profile / css_framework and UiKit css_framework / icon_set seeding, only from the deprecated
 * ui.css_framework and never over host keys; load(): deprecation of ui.css_framework.
 */
final class ApiStudioExtensionPrependTest extends TestCase
{
    public function testWithoutFormKitOrUiKitNothingIsSeeded(): void
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testFormKitProfileIsSeededWithoutCssFrameworkByDefault(): void
    {
        $container = $this->container();
        (new ApiStudioExtension())->prepend($container);

        $seed = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertArrayNotHasKey('css_framework', $seed);
        self::assertTrue($seed['profiles']['api_studio']['convention_requires_translation']);
        self::assertSame(['class' => 'nowo-ui-input'], $seed['profiles']['api_studio']['defaults']['attr']);
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'), 'no legacy css_framework: UiKit keeps its defaults');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacyFrameworks(): iterable
    {
        yield 'bootstrap5' => ['bootstrap5', 'bootstrap'];
        yield 'custom' => ['custom', 'bootstrap'];
        yield 'tailwind' => ['tailwind', 'tailwind'];
        yield 'foundation' => ['foundation', 'foundation'];
    }

    #[DataProvider('legacyFrameworks')]
    public function testLegacyCssFrameworkSeedsFormKitAndUiKit(string $legacy, string $formKit): void
    {
        $container = $this->container([['ui' => ['css_framework' => $legacy]]]);
        (new ApiStudioExtension())->prepend($container);

        self::assertSame($formKit, $container->getExtensionConfig('nowo_form_kit')[0]['css_framework']);
        self::assertSame(['css_framework' => $legacy, 'icon_set' => 'bootstrap-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testHostKeysAreNeverOverwritten(): void
    {
        $container = $this->container(
            [['ui' => ['css_framework' => 'tailwind']]],
            [['css_framework' => 'foundation', 'profiles' => ['api_studio' => ['alias' => 'api_studio']]]],
            [['css_framework' => 'bootstrap5', 'icon_set' => 'tabler-icons']],
        );
        (new ApiStudioExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('nowo_form_kit'), 'nothing prepended to FormKit');
        self::assertCount(1, $container->getExtensionConfig('nowo_ui_kit'), 'nothing prepended to UiKit');
    }

    public function testOnlyTheMissingUiKitKeyIsSeeded(): void
    {
        $container = $this->container([['ui' => ['css_framework' => 'tailwind']]], [], [['icon_set' => 'tabler-icons']]);
        (new ApiStudioExtension())->prepend($container);
        self::assertSame(['css_framework' => 'tailwind'], $container->getExtensionConfig('nowo_ui_kit')[0]);

        $container = $this->container([['ui' => ['css_framework' => 'tailwind']]], [], [['css_framework' => 'tailwind']]);
        (new ApiStudioExtension())->prepend($container);
        self::assertSame(['icon_set' => 'bootstrap-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    #[IgnoreDeprecations]
    public function testLegacyCssFrameworkIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/api-studio-bundle 1.10: The "nowo_api_studio.ui.css_framework" option is deprecated: set "nowo_ui_kit.css_framework" (UiKit page contract) instead.');

        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([['ui' => ['css_framework' => 'tailwind']]], $container);

        self::assertSame('tailwind', $container->getParameter('nowo_api_studio.ui.css_framework'));
    }

    /**
     * Container with the FormKit and UiKit extensions registered (stubs with their aliases) and the given configs.
     *
     * @param list<array<string, mixed>> $apiStudio
     * @param list<array<string, mixed>> $formKit
     * @param list<array<string, mixed>> $uiKit
     */
    private function container(array $apiStudio = [], array $formKit = [], array $uiKit = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new ApiStudioExtension());
        foreach (['nowo_form_kit' => $formKit, 'nowo_ui_kit' => $uiKit, 'nowo_api_studio' => $apiStudio] as $alias => $configs) {
            if ($alias !== 'nowo_api_studio') {
                $container->registerExtension(self::stubExtension($alias));
            }
            foreach ($configs as $config) {
                $container->loadFromExtension($alias, $config);
            }
        }

        return $container;
    }

    private static function stubExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
