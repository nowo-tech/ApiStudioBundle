<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Contract;

use Nowo\ApiStudioBundle\Tests\Support\ApiStudioPageFixtures;
use Nowo\ApiStudioBundle\Twig\ApiStudioExtension;
use Nowo\ApiStudioBundle\Twig\ApiStudioFormsExtension;
use Nowo\FormKitBundle\Twig\LocaleTabsTwigExtension;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\TwigFunction;

use function dirname;

/**
 * REQ-UI-003 page contract: every Api Studio page extends a UiKit scaffold (dashboard, list, show, form); no <html>,
 * CDN, inline handler or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or
 * missing translation keys (strict rule); the CSRF forms of the delete actions keep their hidden fields. Plus the Api
 * Studio regions: workbench tree, service dialogs, request console.
 */
final class PageContractTest extends PageContractTestCase
{
    private ?string $nonce = null;

    public static function pageFixtures(): iterable
    {
        $v = '@NowoApiStudioBundle/';

        yield 'dashboard' => new PageFixture($v . 'dashboard/index.html.twig', ApiStudioPageFixtures::dashboard());
        yield 'dashboard (empty)' => new PageFixture($v . 'dashboard/index.html.twig', ApiStudioPageFixtures::dashboard(true));
        yield 'workspaces' => new PageFixture($v . 'workspace/index.html.twig', ApiStudioPageFixtures::workspaceIndex());
        yield 'workspaces (empty)' => new PageFixture($v . 'workspace/index.html.twig', ApiStudioPageFixtures::workspaceIndex(true));
        yield 'workspace' => new PageFixture($v . 'workspace/show.html.twig', ApiStudioPageFixtures::workspaceShow());
        yield 'workspace edit' => new PageFixture($v . 'workspace/form.html.twig', ApiStudioPageFixtures::workspaceForm());
        yield 'workspace new' => new PageFixture($v . 'workspace/form.html.twig', ApiStudioPageFixtures::workspaceForm(false));
        yield 'services' => new PageFixture($v . 'service/index.html.twig', ApiStudioPageFixtures::serviceIndex());
        yield 'services (empty)' => new PageFixture($v . 'service/index.html.twig', ApiStudioPageFixtures::serviceIndex(true));
        yield 'service' => new PageFixture($v . 'service/show.html.twig', ApiStudioPageFixtures::serviceShow());
        yield 'service edit' => new PageFixture($v . 'service/form.html.twig', ApiStudioPageFixtures::serviceForm());
        yield 'service new' => new PageFixture($v . 'service/form.html.twig', ApiStudioPageFixtures::serviceForm(false));
        yield 'endpoints' => new PageFixture($v . 'endpoint/index.html.twig', ApiStudioPageFixtures::endpointIndex());
        yield 'endpoints (empty)' => new PageFixture($v . 'endpoint/index.html.twig', ApiStudioPageFixtures::endpointIndex(true));
        yield 'request console' => new PageFixture($v . 'endpoint/show.html.twig', ApiStudioPageFixtures::endpointShow());
        yield 'request console (no history)' => new PageFixture($v . 'endpoint/show.html.twig', ApiStudioPageFixtures::endpointShow(false));
        yield 'endpoint edit' => new PageFixture($v . 'endpoint/form.html.twig', ApiStudioPageFixtures::endpointForm());
        yield 'endpoint new' => new PageFixture($v . 'endpoint/form.html.twig', ApiStudioPageFixtures::endpointForm(false));
        yield 'environments' => new PageFixture($v . 'environment/index.html.twig', ApiStudioPageFixtures::environmentIndex());
        yield 'environments (empty)' => new PageFixture($v . 'environment/index.html.twig', ApiStudioPageFixtures::environmentIndex(true));
        yield 'environment' => new PageFixture($v . 'environment/show.html.twig', ApiStudioPageFixtures::environmentShow());
        yield 'environment (no variable)' => new PageFixture($v . 'environment/show.html.twig', ApiStudioPageFixtures::environmentShow(true));
        yield 'environment edit' => new PageFixture($v . 'environment/form.html.twig', ApiStudioPageFixtures::environmentForm());
        yield 'environment new' => new PageFixture($v . 'environment/form.html.twig', ApiStudioPageFixtures::environmentForm(false));
        yield 'import / export hub' => new PageFixture($v . 'import_export/hub.html.twig', ApiStudioPageFixtures::importExportHub());
        yield 'import (workspace)' => new PageFixture($v . 'import_export/import.html.twig', ApiStudioPageFixtures::import());
        yield 'import (service)' => new PageFixture($v . 'import_export/import.html.twig', ApiStudioPageFixtures::import('service'));
        yield 'import (environment)' => new PageFixture($v . 'import_export/import.html.twig', ApiStudioPageFixtures::import('environment'));
    }

    public function testWorkbenchTreeMarksTheOpenBranch(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoApiStudioBundle/endpoint/show.html.twig', ApiStudioPageFixtures::endpointShow()))->html;

        self::assertStringContainsString('data-testid="api-studio-workbench"', $html);
        self::assertMatchesRegularExpression('#class="as-tree-group is-open" data-tree-group>\s*<div class="as-tree-label is-active"#', $html);
        self::assertStringContainsString('class="as-tree-svc is-active"', $html);
        self::assertStringContainsString('id="as-tree-filter"', $html);
    }

    public function testServiceDialogsAreNativeDialogsOpenedFromTheKebab(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoApiStudioBundle/workspace/show.html.twig', ApiStudioPageFixtures::workspaceShow()))->html;

        foreach (['as-service-export-modal', 'as-service-import-modal', 'as-service-delete-modal'] as $id) {
            self::assertMatchesRegularExpression('#<dialog id="' . $id . '"[^>]*data-nowo-ui-dialog#', $html);
        }
        self::assertStringContainsString('data-nowo-ui-kebab', $html);
        self::assertStringContainsString('data-service-action="delete"', $html);
        self::assertStringContainsString('data-delete-token="csrf-delete10"', $html);
        self::assertStringNotContainsString('as-modal-backdrop', $html);
    }

    public function testDeletesGoThroughTheConfirmContract(): void
    {
        $workspace = $this->renderPage(new PageFixture('@NowoApiStudioBundle/workspace/form.html.twig', ApiStudioPageFixtures::workspaceForm()))->html;
        $service   = $this->renderPage(new PageFixture('@NowoApiStudioBundle/service/form.html.twig', ApiStudioPageFixtures::serviceForm()))->html;

        self::assertStringContainsString('data-nowo-ui-confirm', $workspace);
        self::assertStringContainsString('data-testid="api-studio-workspace-delete"', $workspace);
        self::assertStringContainsString('data-nowo-confirm-expected="Users API"', $service);
        self::assertMatchesRegularExpression('#name="confirmation"[^>]*value="Users API"|value="Users API"[^>]*name="confirmation"#', $service);
    }

    public function testRequestConsoleUsesDialogsAndCarriesTheCspNonce(): void
    {
        $page = new PageFixture('@NowoApiStudioBundle/endpoint/show.html.twig', ApiStudioPageFixtures::endpointShow());
        $html = $this->renderPage($page)->html;

        self::assertMatchesRegularExpression('#<dialog id="tester-example-modal"[^>]*data-nowo-ui-dialog#', $html);
        self::assertMatchesRegularExpression('#<dialog id="tester-example-delete-modal"[^>]*data-nowo-ui-dialog#', $html);
        self::assertStringContainsString('<script src="/assets/api-tester.js" defer></script>', $html);
        self::assertStringContainsString('data-testid="api-studio-history"', $html);

        $this->nonce = 'n0nce';
        self::assertStringContainsString('<script src="/assets/api-tester.js" defer nonce="n0nce"></script>', $this->renderPage($page)->html);
    }

    protected static function templatePaths(): array
    {
        return ['NowoApiStudioBundle' => dirname(__DIR__, 3) . '/src/Resources/views'] + self::formKitViews();
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    protected static function formThemes(): array
    {
        return ['form_div_layout.html.twig'];
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new ApiStudioFormsExtension(ApiStudioPageFixtures::formFactory()));
        $twig->addExtension(new LocaleTabsTwigExtension(['es', 'en'], 'en', true));
        $twig->addFunction(new TwigFunction('api_studio_nav_tree', ApiStudioPageFixtures::navigationTree(...)));
        $twig->addFunction(new TwigFunction('api_studio_method_class', static fn (string $method): string => 'as-method-' . strtolower($method)));
        $twig->addFunction(new TwigFunction('api_studio_var', static fn (string $key): string => '{{' . $key . '}}'));
        $twig->addGlobal('nowo_api_studio_locales', ['es', 'en']);
        $twig->addGlobal(ApiStudioExtension::GLOBAL_LAYOUT_TEMPLATE, null);
        $twig->addGlobal(ApiStudioExtension::GLOBAL_CSS_FRAMEWORK, null);

        if ($this->nonce !== null) {
            $request = Request::create('/');
            $request->attributes->set('csp_nonce', $this->nonce);
            $request->attributes->set('_route', 'nowo_ui_contract_page');
            $request->attributes->set('_route_params', []);
            $stack = new RequestStack();
            $stack->push($request);
            $twig->getGlobals()['app']->setRequestStack($stack);
        }
    }

    /**
     * FormKit views (the endpoint form renders its locale tabs partial).
     *
     * @return array<string, string>
     */
    private static function formKitViews(): array
    {
        return ['NowoFormKitBundle' => dirname(__DIR__, 3) . '/vendor/nowo-tech/form-kit-bundle/src/Resources/views'];
    }
}
