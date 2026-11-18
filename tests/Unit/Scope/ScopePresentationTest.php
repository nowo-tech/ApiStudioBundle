<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Scope;

use Nowo\ApiStudioBundle\Controller\LocaleController;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use Nowo\ApiStudioBundle\Twig\ApiStudioRoutingExtension;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Nowo\UiKitBundle\View\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * 1.17 presentation: the scope layout, the page variable, the Twig functions, the locale switch back to the same
 * mount, and list rows without an authorization service.
 */
final class ScopePresentationTest extends TestCase
{
    public function testAScopedRequestRendersInTheScopeLayout(): void
    {
        $request = new Request();
        $stack   = new RequestStack([$request]);
        $context = new ScopeContext($stack, true);
        $twig    = new Environment(new ArrayLoader([
            'tenant.html.twig'  => '{% block nowo_ui_page_header %}{% endblock %}',
            'project.html.twig' => '{% block nowo_ui_page_header %}{% endblock %}',
        ]));
        $pages = new ApiStudioPages('tenant.html.twig', $twig, 'project.html.twig', $context);

        self::assertSame('tenant.html.twig', $pages->layout());
        self::assertArrayHasKey('nowo_api_studio_scope', $pages->context([]));
        self::assertNull($context->current());

        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:a'));
        self::assertSame('project.html.twig', $pages->layout());
        $scope = $pages->context([])['nowo_api_studio_scope'];
        self::assertInstanceOf(WorkspaceScope::class, $scope);
        self::assertSame('project:a', $scope->value);

        // No scope layout: the tenant one everywhere.
        self::assertSame('tenant.html.twig', (new ApiStudioPages('tenant.html.twig', $twig, '', $context))->layout());
        self::assertNull((new ApiStudioPages(null, $twig, null, $context))->layout());
    }

    public function testTwigFunctions(): void
    {
        $request = new Request(attributes: ['_route' => 'nowo_api_studio_scoped_dashboard']);
        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:a'));
        $stack     = new RequestStack([$request]);
        $extension = new ApiStudioRoutingExtension(new ApiStudioUrlGenerator($this->router(), $stack, true), new ScopeContext($stack, true));

        self::assertSame(['api_studio_path', 'nowo_api_studio_scope'], array_map(static fn ($function): string => $function->getName(), $extension->getFunctions()));
        self::assertSame('/nowo_api_studio_scoped_workspace_show?id=3', $extension->path('nowo_api_studio_workspace_show', ['id' => 3]));
        self::assertSame('project:a', $extension->scope()?->value);
    }

    public function testTheLocaleSwitchGoesBackToTheSameMount(): void
    {
        $request = Request::create('http://app.test/p/a/api-studio/locale/es');
        $request->attributes->set('_route', 'nowo_api_studio_scoped_locale_switch');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack      = new RequestStack([$request]);
        $controller = new LocaleController(new LocaleManager($stack, ['en', 'es'], 'en'), new ApiStudioUrlGenerator($this->router(), $stack, true));

        self::assertSame('/nowo_api_studio_scoped_dashboard', $controller->switch('es', $request)->headers->get('Location'));
    }

    public function testRowsWithoutAnAuthorizationServiceHaveNoBadge(): void
    {
        $factory = new ApiStudioPageFactory($this->router());
        $page    = $factory->workspaceIndex([new ApiWorkspace('A', 'a')], new Pagination(1, 50, 1, 1, 'nowo_api_studio_workspace_index'));

        self::assertNull($page->rows[0]['scope']);
    }

    private function router(): UrlGeneratorInterface
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters)));

        return $router;
    }
}
