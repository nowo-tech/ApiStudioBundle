<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Scope;

use Nowo\ApiStudioBundle\Routing\ApiStudioRouteScopeListener;
use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\ScopeListener;
use Nowo\ApiStudioBundle\Scope\ScopeResolverInterface;
use Nowo\ApiStudioBundle\Scope\ScopeRouteContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;

/**
 * 1.17: the scope listener (resolve once per main request, 404 on a scoped mount without a scope, no leak between
 * requests), the scope-aware URL generator and the scope.path placeholders of the router context.
 */
final class ScopeRequestListenersTest extends TestCase
{
    public function testResolvesTheScopeOfAStudioMainRequest(): void
    {
        $resolver = $this->resolver(new WorkspaceScope('project:a'));
        $request  = $this->request('nowo_api_studio_scoped_workspace_index', ['project' => 'a']);
        $tenants  = new ApiStudioTenantContext(new RequestStack([$request]), true);
        $request->attributes->set(ApiStudioTenantContext::REQUEST_ATTRIBUTE, new ApiStudioTenant('org', 'acme'));

        (new ScopeListener($resolver, true, tenantContext: $tenants))->onKernelRequest($this->event($request));

        self::assertSame('project:a', $request->attributes->get(ScopeContext::REQUEST_ATTRIBUTE)?->value);
        self::assertSame([['nowo_api_studio_scoped_workspace_index', ['project' => 'a'], true, 'org:acme']], $resolver->seen);
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', 28]], ScopeListener::getSubscribedEvents());
    }

    public function testAScopedMountWithoutAScopeIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new ScopeListener($this->resolver(null), true))->onKernelRequest($this->event($this->request('nowo_api_studio_scoped_dashboard')));
    }

    public function testATenantLevelRouteWithoutAScopeIsTenantLevel(): void
    {
        $request = $this->request('nowo_api_studio_dashboard');
        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:stale'));
        (new ScopeListener($this->resolver(null), true))->onKernelRequest($this->event($request));

        // Always written: nothing of an earlier value survives.
        self::assertTrue($request->attributes->has(ScopeContext::REQUEST_ATTRIBUTE));
        self::assertNull($request->attributes->get(ScopeContext::REQUEST_ATTRIBUTE));
    }

    public function testScopesOffMakeTheScopedMount404AndLeaveOtherRoutesAlone(): void
    {
        $resolver = $this->resolver(new WorkspaceScope('project:a'));
        $request  = $this->request('nowo_api_studio_dashboard');
        (new ScopeListener($resolver, false))->onKernelRequest($this->event($request));
        self::assertNull($request->attributes->get(ScopeContext::REQUEST_ATTRIBUTE));
        self::assertSame([], $resolver->seen);

        $this->expectException(NotFoundHttpException::class);
        (new ScopeListener($resolver, false))->onKernelRequest($this->event($this->request('nowo_api_studio_scoped_dashboard')));
    }

    public function testSubRequestsAndOtherRoutesAreIgnored(): void
    {
        $resolver = $this->resolver(new WorkspaceScope('project:a'));
        $listener = new ScopeListener($resolver, true);
        $listener->onKernelRequest($this->event($this->request('nowo_api_studio_dashboard'), HttpKernelInterface::SUB_REQUEST));
        $listener->onKernelRequest($this->event($this->request('app_home')));
        $listener->onKernelRequest($this->event(new Request()));

        self::assertSame([], $resolver->seen);
    }

    public function testTheUrlGeneratorTargetsTwinsOnAScopedMountOnly(): void
    {
        $router = new class implements UrlGeneratorInterface {
            public RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($referenceType === self::ABSOLUTE_URL ? '#abs' : '');
            }
        };
        $stack = new RequestStack([$this->request('nowo_api_studio_scoped_workspace_index')]);

        $scoped = new ApiStudioUrlGenerator($router, $stack, true);
        self::assertSame('/nowo_api_studio_scoped_workspace_show', $scoped->generate('nowo_api_studio_workspace_show'));
        self::assertSame('/nowo_api_studio_scoped_workspace_show#abs', $scoped->generate('nowo_api_studio_workspace_show', [], UrlGeneratorInterface::ABSOLUTE_URL));
        self::assertSame('/app_home', $scoped->generate('app_home'));

        self::assertSame('/nowo_api_studio_workspace_show', (new ApiStudioUrlGenerator($router, $stack, false))->generate('nowo_api_studio_workspace_show'));
        self::assertSame('nowo_api_studio_workspace_show', (new ApiStudioUrlGenerator($router, new RequestStack([$this->request('nowo_api_studio_dashboard')]), true))->routeName('nowo_api_studio_workspace_show'));
        self::assertSame('nowo_api_studio_workspace_show', (new ApiStudioUrlGenerator($router, null, true))->routeName('nowo_api_studio_workspace_show'));

        $context = new RequestContext('/base');
        $scoped->setContext($context);
        self::assertSame($context, $scoped->getContext());
    }

    public function testScopedPlaceholdersAreRouterDefaultsOnTheScopedMountAndRestored(): void
    {
        $router = new class implements RequestContextAwareInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
        $listener = new ApiStudioRouteScopeListener($router, ['org'], scopedPlaceholders: ['org', 'project']);

        $request = $this->request('nowo_api_studio_scoped_dashboard', ['org' => 'acme', 'project' => 'alpha']);
        $listener->onKernelRequest($this->event($request));
        self::assertSame(['org' => 'acme', 'project' => 'alpha'], $router->getContext()->getParameters());
        $listener->onKernelFinishRequest(new FinishRequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([], $router->getContext()->getParameters());

        // A tenant-level route sets only the ui.path placeholders.
        $request = $this->request('nowo_api_studio_dashboard', ['org' => 'acme', 'project' => 'alpha']);
        $listener->onKernelRequest($this->event($request));
        self::assertSame(['org' => 'acme'], $router->getContext()->getParameters());

        // Worker safety net: both lists are cleared.
        $router->getContext()->setParameter('project', 'left');
        $listener->reset();
        self::assertSame([], $router->getContext()->getParameters());

        // Only scoped placeholders: reset still clears them.
        $onlyScoped = new ApiStudioRouteScopeListener($router, [], scopedPlaceholders: ['project']);
        $router->getContext()->setParameter('project', 'left');
        $onlyScoped->reset();
        self::assertSame([], $router->getContext()->getParameters());
    }

    /**
     * @return object{seen: list<array{string, array<string, string>, bool, string}>}&ScopeResolverInterface
     */
    private function resolver(?WorkspaceScope $scope): ScopeResolverInterface
    {
        return new class($scope) implements ScopeResolverInterface {
            /** @var list<array{string, array<string, string>, bool, string}> */
            public array $seen = [];

            public function __construct(private readonly ?WorkspaceScope $scope)
            {
            }

            public function resolve(ScopeRouteContext $route, ApiStudioTenant $tenant): ?WorkspaceScope
            {
                $this->seen[] = [$route->routeName, $route->parameters, $route->scopedMount, $tenant->key()];

                return $this->scope;
            }
        };
    }

    /**
     * @param array<string, string> $attributes
     */
    private function request(string $route, array $attributes = []): Request
    {
        return new Request(attributes: ['_route' => $route] + $attributes);
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->kernel(), $request, $type);
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
