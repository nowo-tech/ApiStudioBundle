<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Ui;

use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\UiKitBundle\View\Label;
use Throwable;
use Twig\Environment;

use function in_array;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layout the Api Studio pages extend
 * (`ui.layout_template`, null = `nowo_ui_kit.layouts.admin`) and wraps a pre-1.10 layout (one that renders
 * `{% block body %}` instead of the UiKit canonical blocks) in a deprecated bridge. Configuration only: no request
 * state, safe in worker mode.
 */
final readonly class ApiStudioPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoApiStudioBundle`). */
    public const BUNDLE = 'NowoApiStudioBundle';

    /** Translation domain of the bundle labels (REQ-I18N-003). */
    public const DOMAIN = 'NowoApiStudioBundle';

    /** BC bridge around a pre-1.10 layout that renders `{% block body %}` (removed in 2.0). */
    public const LEGACY_ADMIN_BRIDGE = '@NowoApiStudioBundle/_legacy_layout.html.twig';

    /** The 1.x default of `ui.layout_template`: the removed bundle layout, read as "no layout override". */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoApiStudioBundle/layout.html.twig';

    /** `ui.header_actions` default: the create actions are buttons of the page header row (1.22 behaviour). */
    public const HEADER_ACTIONS_HEADER = 'header';

    /** `ui.header_actions`: the create actions are UiKit `View\Action` entries of the page-actions menu. */
    public const HEADER_ACTIONS_PAGE_ACTIONS = 'page_actions';

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /** Canonical blocks a pre-1.10 layout may already render (the bridge skips them). */
    private const LEGACY_HOST_BLOCKS = ['nowo_ui_flashes', 'nowo_ui_styles', 'nowo_ui_scripts'];

    /**
     * @param string|null $layoutTemplate `ui.layout_template` (null = the UiKit admin layout)
     * @param Environment|null $twig Twig, to tell a pre-1.10 layout from a canonical one (null = never bridged)
     * @param string|null $scopeLayoutTemplate 1.17 `scope.layout_template`: layout of the pages of a scoped request
     *                                         (null = `ui.layout_template`)
     * @param ScopeContext|null $scopeContext 1.17: the host scope of the request
     * @param string $headerActions 1.23 `ui.header_actions`: `header` (default) or `page_actions`
     */
    public function __construct(
        private ?string $layoutTemplate = null,
        private ?Environment $twig = null,
        private ?string $scopeLayoutTemplate = null,
        private ?ScopeContext $scopeContext = null,
        private string $headerActions = self::HEADER_ACTIONS_HEADER,
    ) {
    }

    /**
     * Whether the create actions of the page headers are entries of the page-actions menu (`ui.header_actions:
     * page_actions`, 1.23) instead of buttons of the header row.
     */
    public function headerActionsInPageMenu(): bool
    {
        return $this->headerActions === self::HEADER_ACTIONS_PAGE_ACTIONS;
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Layout the scaffolds extend (`page.layout`): `ui.layout_template`, the legacy bridge for a pre-1.10 layout,
     * else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        $layout = $this->configuredLayout();
        if ($layout === null) {
            return null;
        }

        return $this->legacy($layout) !== null ? self::LEGACY_ADMIN_BRIDGE : $layout;
    }

    /**
     * Shared variables of the Api Studio pages (added to the controller variables): the pre-1.10 layout wrapped by
     * the deprecated bridge and the canonical blocks it already renders.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        $layout = $this->configuredLayout();
        $legacy = $layout !== null ? $this->legacy($layout, false) : null;

        return $vars + [
            'nowo_api_studio_legacy_layout' => $legacy,
            'nowo_api_studio_legacy_blocks' => $this->hostBlocks($legacy),
            // 1.17: the host scope of the request (null at tenant level), e.g. for the host's project layout.
            'nowo_api_studio_scope' => $this->scopeContext?->current(),
        ];
    }

    private function configuredLayout(): ?string
    {
        // 1.17: a scoped request renders in the host's scope layout (e.g. the project page with its tabs).
        if ($this->scopeLayoutTemplate !== null && $this->scopeLayoutTemplate !== '' && $this->scopeContext?->current() instanceof WorkspaceScope) {
            return $this->scopeLayoutTemplate;
        }

        if (in_array($this->layoutTemplate, [null, '', self::LEGACY_DEFAULT_LAYOUT], true)) {
            return null;
        }

        return $this->layoutTemplate;
    }

    /**
     * Canonical blocks a pre-1.10 layout defines itself (the bridge does not render them a second time).
     *
     * @return list<string>
     */
    private function hostBlocks(?string $legacy): array
    {
        if ($legacy === null || !$this->twig instanceof Environment) {
            return [];
        }

        $template = $this->twig->load($legacy);
        $context  = $this->twig->mergeGlobals([]);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block, $context)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The configured layout when it is a pre-1.10 layout (it does not define the UiKit canonical blocks), else null.
     */
    private function legacy(string $layout, bool $deprecate = true): ?string
    {
        if (!$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        if ($deprecate) {
            trigger_deprecation('nowo-tech/api-studio-bundle', '1.10', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a deprecated bridge. Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig) or set nowo_api_studio.ui.layout_template to null to use nowo_ui_kit.layouts.admin; see docs/UPGRADING.md.', $layout);
        }

        return $layout;
    }
}
