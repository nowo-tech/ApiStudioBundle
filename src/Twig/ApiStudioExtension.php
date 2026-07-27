<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Twig;

use Nowo\ApiStudioBundle\Service\LocaleManager;
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Exposes API Studio UI globals and helpers to Twig templates.
 */
final class ApiStudioExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_api_studio_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_api_studio_css_framework';

    public function __construct(
        private readonly LocaleManager $localeManager,
        private readonly StudioNavigationProvider $navigationProvider,
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('api_studio_method_class', [$this, 'methodClass']),
            new TwigFunction('api_studio_var', [$this, 'formatVariable']),
        ];
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'nowo_api_studio_locales'    => $this->localeManager->getEnabledLocales(),
            'nowo_api_studio_nav_tree'   => $this->navigationProvider->buildTree(),
            self::GLOBAL_LAYOUT_TEMPLATE => $this->layoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK   => $this->cssFramework,
        ];
    }

    public function methodClass(string $method): string
    {
        return match (strtoupper($method)) {
            'GET'    => 'as-method-get',
            'POST'   => 'as-method-post',
            'PUT'    => 'as-method-put',
            'PATCH'  => 'as-method-patch',
            'DELETE' => 'as-method-delete',
            'SOAP'   => 'as-method-soap',
            default  => 'as-method-default',
        };
    }

    public function formatVariable(string $key): string
    {
        return VariableSyntax::formatKey($key);
    }
}
