<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Ui;

use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Layout resolution of the UiKit page contract: null / the 1.x default = UiKit admin layout, canonical layouts used
 * as is, pre-1.10 layouts wrapped in the deprecated bridge.
 */
final class ApiStudioPagesTest extends TestCase
{
    public function testLabelUsesTheBundleDomain(): void
    {
        $label = ApiStudioPages::label('page.dashboard', ['%n%' => 1]);

        self::assertSame('page.dashboard', $label->key);
        self::assertSame('NowoApiStudioBundle', $label->domain);
        self::assertSame(['%n%' => 1], $label->params);
    }

    public function testNoLayoutOrTheLegacyDefaultMeansTheUiKitLayout(): void
    {
        foreach ([null, '', ApiStudioPages::LEGACY_DEFAULT_LAYOUT] as $layout) {
            $pages = new ApiStudioPages($layout, $this->twig());

            self::assertNull($pages->layout());
            self::assertSame(['page' => 'x', 'nowo_api_studio_legacy_layout' => null, 'nowo_api_studio_legacy_blocks' => []], $pages->context(['page' => 'x']));
        }
    }

    public function testCanonicalLayoutIsUsedAsIs(): void
    {
        $pages = new ApiStudioPages('canonical.html.twig', $this->twig());

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertNull($pages->context([])['nowo_api_studio_legacy_layout']);
    }

    public function testWithoutTwigTheConfiguredLayoutIsTrusted(): void
    {
        $pages = new ApiStudioPages('legacy.html.twig');

        self::assertSame('legacy.html.twig', $pages->layout());
        self::assertSame([], $pages->context([])['nowo_api_studio_legacy_blocks']);
    }

    public function testUnloadableLayoutIsReturnedUnchanged(): void
    {
        $pages = new ApiStudioPages('missing.html.twig', $this->twig());

        self::assertSame('missing.html.twig', $pages->layout());
        self::assertNull($pages->context([])['nowo_api_studio_legacy_layout']);
    }

    #[IgnoreDeprecations]
    public function testPreTenLayoutIsWrappedInTheDeprecatedBridge(): void
    {
        $this->expectUserDeprecationMessageMatches('/The layout "legacy\.html\.twig" does not render the UiKit canonical blocks/');

        $pages = new ApiStudioPages('legacy.html.twig', $this->twig());

        self::assertSame(ApiStudioPages::LEGACY_ADMIN_BRIDGE, $pages->layout());
        $context = $pages->context(['page' => 'x']);
        self::assertSame('legacy.html.twig', $context['nowo_api_studio_legacy_layout']);
        self::assertSame([], $context['nowo_api_studio_legacy_blocks']);
    }

    public function testBridgeSkipsTheCanonicalBlocksTheLegacyLayoutRendersItself(): void
    {
        $context = (new ApiStudioPages('legacy_with_blocks.html.twig', $this->twig()))->context([]);

        self::assertSame('legacy_with_blocks.html.twig', $context['nowo_api_studio_legacy_layout']);
        self::assertSame(['nowo_ui_flashes', 'nowo_ui_scripts'], $context['nowo_api_studio_legacy_blocks']);
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            'canonical.html.twig'          => '{% block nowo_ui_page_header %}{% endblock %}{% block nowo_ui_content %}{% endblock %}',
            'legacy.html.twig'             => '<html>{% block body %}{% endblock %}</html>',
            'legacy_with_blocks.html.twig' => '{% block body %}{% endblock %}{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_scripts %}{% endblock %}',
        ]));
    }
}
