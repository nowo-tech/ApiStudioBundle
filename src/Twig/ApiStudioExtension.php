<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Twig;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
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
    /** `ui.layout_template` (null = the UiKit admin layout); kept for host templates, the pages use page.layout. */
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_api_studio_layout_template';

    /** Deprecated `ui.css_framework` (null when not set); the CSS stack is nowo_ui_kit.css_framework. */
    public const GLOBAL_CSS_FRAMEWORK = 'nowo_api_studio_css_framework';

    /** `nowo_api_studio.scripts.mode`: pre/post scripts run in the page (1.10 behaviour, needs 'unsafe-eval'). */
    public const SCRIPTS_INLINE = 'inline';

    /** Scripts are neither rendered nor run (stored scripts are kept). */
    public const SCRIPTS_DISABLED = 'disabled';

    public const SCRIPTS_MODES = [self::SCRIPTS_INLINE, self::SCRIPTS_DISABLED];

    public function __construct(
        private readonly LocaleManager $localeManager,
        private readonly StudioNavigationProvider $navigationProvider,
        private readonly ?string $layoutTemplate = null,
        private readonly ?string $cssFramework = null,
        private readonly string $scriptsMode = self::SCRIPTS_INLINE,
        private readonly ?ApiStudioAuthorization $authorization = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('api_studio_method_class', [$this, 'methodClass']),
            new TwigFunction('api_studio_var', [$this, 'formatVariable']),
            new TwigFunction('api_studio_nav_tree', [$this, 'navigationTree']),
            new TwigFunction('nowo_api_studio_scripts_enabled', [$this, 'scriptsEnabled']),
            new TwigFunction('nowo_api_studio_can', [$this, 'can']),
        ];
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'nowo_api_studio_locales'    => $this->localeManager->getEnabledLocales(),
            self::GLOBAL_LAYOUT_TEMPLATE => $this->layoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK   => $this->cssFramework,
        ];
    }

    /**
     * Built at call time: Twig keeps globals for the whole Environment lifetime (the worker, when nothing resets it).
     *
     * @return list<array<string, mixed>>
     */
    public function navigationTree(): array
    {
        return $this->navigationProvider->buildTree();
    }

    /**
     * False with `scripts.mode: disabled`: the console renders no script tab and sends no script code to the browser.
     */
    public function scriptsEnabled(): bool
    {
        return $this->scriptsMode !== self::SCRIPTS_DISABLED;
    }

    /**
     * Whether the current user may do $action (an {@see ApiStudioAction} value such as `workspace.edit`) on $workspace:
     * templates hide what is not granted. Unknown actions are false.
     */
    public function can(string $action, ?ApiWorkspace $workspace = null): bool
    {
        $case = ApiStudioAction::tryFrom($action);
        if (!$case instanceof ApiStudioAction) {
            return false;
        }

        return !$this->authorization instanceof ApiStudioAuthorization || $this->authorization->can($case, $workspace);
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
