<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Tenancy;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Routing\ApiStudioRouteScopeListener;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Security\LegacyPermissionChecker;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantListener;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantResolverInterface;
use Nowo\ApiStudioBundle\Twig\ApiStudioExtension as TwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Stringable;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use UnexpectedValueException;

use function range;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Units of the 1.11 host hooks: tenant value object, context and listener, authorization decisions, history actor,
 * route scope parameters, configuration refusals.
 */
final class HostHooksUnitTest extends TestCase
{
    public function testTenantValueObject(): void
    {
        $tenant = new ApiStudioTenant('org', 'a-1');
        self::assertSame('org:a-1', $tenant->key());
        self::assertFalse($tenant->isInstance());
        self::assertTrue(ApiStudioTenant::instance()->isInstance());
        self::assertSame('', ApiStudioTenant::instance()->key());
        self::assertTrue($tenant->equals(new ApiStudioTenant('org', 'a-1')));
        self::assertFalse($tenant->equals(new ApiStudioTenant('org', 'a-2')));

        $this->expectException(InvalidArgumentException::class);
        new ApiStudioTenant('Org!', '');
    }

    public function testContextRunsAsATenantAndResets(): void
    {
        $stack   = new RequestStack();
        $context = new ApiStudioTenantContext($stack, true);
        self::assertTrue($context->isEnabled());
        self::assertTrue($context->current()->isInstance());

        $request = new Request();
        $request->attributes->set(ApiStudioTenantContext::REQUEST_ATTRIBUTE, new ApiStudioTenant('org', 'req'));
        $stack->push($request);
        self::assertSame('org:req', $context->current()->key());

        $inside = $context->runAs(new ApiStudioTenant('org', 'cli'), static fn (): ApiStudioTenant => $context->current());
        self::assertSame('org:cli', $inside->key());
        self::assertSame('org:req', $context->current()->key(), 'restored after the call');

        $context->runAs(new ApiStudioTenant('org', 'x'), static function () use ($context): void {
            $context->reset();
        });
        self::assertSame('org:req', $context->current()->key());
        self::assertFalse((new ApiStudioTenantContext())->isEnabled());
    }

    public function testListenerResolvesStudioMainRequestsOnly(): void
    {
        $resolver = new class implements ApiStudioTenantResolverInterface {
            public function resolve(Request $request): ?ApiStudioTenant
            {
                return $request->attributes->get('org') === 'acme' ? new ApiStudioTenant('org', 'acme') : null;
            }
        };
        $listener = new ApiStudioTenantListener($resolver, true);
        $kernel   = $this->createStub(HttpKernelInterface::class);
        self::assertSame(29, ApiStudioTenantListener::getSubscribedEvents()['kernel.request'][1]);

        $host = new Request(attributes: ['_route' => 'app_home']);
        $listener->onKernelRequest(new RequestEvent($kernel, $host, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($host->attributes->has(ApiStudioTenantContext::REQUEST_ATTRIBUTE));

        $sub = new Request(attributes: ['_route' => 'nowo_api_studio_dashboard']);
        $listener->onKernelRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertFalse($sub->attributes->has(ApiStudioTenantContext::REQUEST_ATTRIBUTE));

        (new ApiStudioTenantListener($resolver, false))->onKernelRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($sub->attributes->has(ApiStudioTenantContext::REQUEST_ATTRIBUTE), 'tenancy off: nothing resolved');

        $studio = new Request(attributes: ['_route' => 'nowo_api_studio_dashboard', 'org' => 'acme']);
        $listener->onKernelRequest(new RequestEvent($kernel, $studio, HttpKernelInterface::MAIN_REQUEST));
        self::assertInstanceOf(ApiStudioTenant::class, $studio->attributes->get(ApiStudioTenantContext::REQUEST_ATTRIBUTE));

        $this->expectException(NotFoundHttpException::class);
        $listener->onKernelRequest(new RequestEvent($kernel, new Request(attributes: ['_route' => 'nowo_api_studio_dashboard', 'org' => 'nope']), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testAuthorizationDecisions(): void
    {
        $checker = new class implements ApiStudioPermissionCheckerInterface {
            public int $calls = 0;

            /** @var list<int>|null */
            public ?array $visible = null;

            public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
            {
                ++$this->calls;

                return $action !== ApiStudioAction::WorkspaceCreate && $action !== ApiStudioAction::WorkspaceManage;
            }

            public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
            {
                return $this->visible;
            }
        };
        $stack = new RequestStack();
        $stack->push(new Request());
        $tokens = new TokenStorage();
        $user   = new InMemoryUser('ada', null);
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $authorization = new ApiStudioAuthorization($checker, null, $tokens, $stack);
        $workspace     = new ApiWorkspace('W', 'w');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 7);

        self::assertSame($user, $authorization->user());
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView), 'a workspace action needs a workspace');
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceEdit, $workspace));
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceEdit, $workspace));
        self::assertSame(1, $checker->calls, 'memoised on the request');
        self::assertSame($workspace, $authorization->requireWorkspace($workspace));

        try {
            $authorization->requireWorkspace($workspace, ApiStudioAction::WorkspaceManage);
            self::fail('403 expected');
        } catch (AccessDeniedHttpException) {
        }
        try {
            $authorization->denyUnlessGranted(ApiStudioAction::WorkspaceCreate);
            self::fail('403 expected');
        } catch (AccessDeniedHttpException) {
        }
        $authorization->denyUnlessGranted(ApiStudioAction::StudioOpen);

        $checker->visible = range(1, ApiStudioAuthorization::MAX_VISIBLE_IDS + 1);
        $this->expectException(LogicException::class);
        (new ApiStudioAuthorization($checker, null, null, null))->scope();
    }

    public function testScopeAndLegacyChecker(): void
    {
        $workspace = (new ApiWorkspace('W', 'w'))->assignTenant(new ApiStudioTenant('org', 'a'));
        self::assertTrue(ApiStudioScope::unrestricted()->contains($workspace));
        self::assertFalse((new ApiStudioScope(new ApiStudioTenant('org', 'b'), true))->contains($workspace));
        self::assertFalse((new ApiStudioScope(new ApiStudioTenant('org', 'a'), true, []))->contains($workspace));

        $legacy = new LegacyPermissionChecker();
        self::assertTrue($legacy->isGranted(null, ApiStudioAction::WorkspaceManage, new ApiStudioSubject(ApiStudioTenant::instance())));
        self::assertNull($legacy->visibleWorkspaceIds(null, ApiStudioTenant::instance()));
        self::assertFalse(ApiStudioAction::StudioOpen->needsWorkspace());
    }

    public function testSavedWorkspaceCannotChangeTenant(): void
    {
        $workspace = new ApiWorkspace('W', 'w');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 3);
        $workspace->assignTenant(ApiStudioTenant::instance());

        $this->expectException(LogicException::class);
        $workspace->assignTenant(new ApiStudioTenant('org', 'other'));
    }

    public function testHistoryActorIds(): void
    {
        $scope = new ApiStudioHistoryScope(ApiStudioHistoryScope::SCOPE_USER);
        self::assertTrue($scope->isPerUser());
        self::assertFalse((new ApiStudioHistoryScope())->isPerUser());
        self::assertNull($scope->actorId(null));
        self::assertSame('u:ada', $scope->actorId(new InMemoryUser('ada', null)));
        $hashed = (string) $scope->actorId(new InMemoryUser(str_repeat('a', 80), null));
        self::assertSame(64, strlen($hashed), 'long identifiers are hashed');
        self::assertStringStartsWith('h:', $hashed);
        // ER-I2: an identifier equal to a hashed id stays in its own namespace.
        self::assertNotSame($hashed, $scope->actorId(new InMemoryUser(substr($hashed, 2), null)));
        self::assertSame('u:as-string', $scope->actorId(new class implements Stringable {
            public function __toString(): string
            {
                return 'as-string';
            }
        }));
        self::assertNull($scope->actorId(new stdClass()));

        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'public-id';
            }
        };
        self::assertSame('public-id', (new ApiStudioHistoryScope(ApiStudioHistoryScope::SCOPE_USER, $resolver))->actorId(new InMemoryUser('ada', null)));

        $bad = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return str_repeat('x', 65);
            }
        };
        $this->expectException(UnexpectedValueException::class);
        (new ApiStudioHistoryScope(ApiStudioHistoryScope::SCOPE_USER, $bad))->actorId(new InMemoryUser('ada', null));
    }

    public function testRouteScopeParametersAreSetAndRestored(): void
    {
        $context = new RequestContext();
        $context->setParameter('org', 'previous');
        $router = new class($context) implements RequestContextAwareInterface {
            public function __construct(private RequestContext $context)
            {
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
        $listener = new ApiStudioRouteScopeListener($router, ['org', 'team']);
        $kernel   = $this->createStub(HttpKernelInterface::class);
        self::assertSame(['org', 'team'], ApiStudioRouteScopeListener::placeholdersOf('/o/{org}/t/{team}/x'));

        $request = new Request(attributes: ['_route' => 'nowo_api_studio_dashboard', 'org' => 'acme', 'team' => 'blue']);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('acme', $context->getParameter('org'));
        self::assertSame('blue', $context->getParameter('team'));

        $listener->onKernelFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('previous', $context->getParameter('org'));
        self::assertFalse($context->hasParameter('team'));

        $context->setParameter('team', 'left-over');
        $listener->reset();
        self::assertFalse($context->hasParameter('org'), 'reset() clears the placeholders (worker safety net)');
        self::assertFalse($context->hasParameter('team'));
        $context->setParameter('org', 'previous');
        (new ApiStudioRouteScopeListener($router))->reset();
        self::assertTrue($context->hasParameter('org'), 'no placeholders: nothing to clear');

        $host = new Request(attributes: ['_route' => 'app_home', 'org' => 'zzz']);
        $listener->onKernelRequest(new RequestEvent($kernel, $host, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelFinishRequest(new FinishRequestEvent($kernel, $host, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('previous', $context->getParameter('org'));
    }

    public function testUnmappedStudioRouteIsNotFound(): void
    {
        $subscriber = new ApiStudioAccessSubscriber([], null, true, null, null, true, new ApiStudioAuthorization());
        $request    = new Request(attributes: ['_route' => 'nowo_api_studio_not_mapped']);

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController(new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST));
    }

    public function testJsonRoutesAnswerJsonWhenForbidden(): void
    {
        $subscriber = new ApiStudioAccessSubscriber([]);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $json = new ExceptionEvent($kernel, new Request(attributes: ['_route' => 'nowo_api_studio_endpoint_save_documentation']), HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException('no'));
        $subscriber->onKernelException($json);
        $response = $json->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $response->getContent());

        foreach ([['nowo_api_studio_workspace_edit', new AccessDeniedHttpException()], ['nowo_api_studio_execute', new NotFoundHttpException()]] as [$route, $exception]) {
            $event = new ExceptionEvent($kernel, new Request(attributes: ['_route' => $route]), HttpKernelInterface::MAIN_REQUEST, $exception);
            $subscriber->onKernelException($event);
            self::assertNull($event->getResponse(), $route);
        }
    }

    public function testTwigCanRejectsUnknownActions(): void
    {
        $twig = new TwigExtension(
            new LocaleManager(new RequestStack(), ['en'], 'en'),
            new StudioNavigationProvider($this->createStub(ApiWorkspaceRepository::class), $this->createStub(UrlGeneratorInterface::class)),
            authorization: new ApiStudioAuthorization(),
        );

        self::assertFalse($twig->can('workspace.fly'));
        self::assertTrue($twig->can('studio.open'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedConfigurations(): iterable
    {
        yield 'tenancy without resolver' => [['tenancy' => ['enabled' => true]], 'tenancy.resolver'];
        yield 'reserved placeholder' => [['ui' => ['path' => '/w/{workspaceId}/api-studio']], 'workspaceId'];
        yield 'requirement of no placeholder' => [['ui' => ['path' => '/o/{org}/api-studio', 'path_requirements' => ['team' => '\d+']]], 'team'];
        yield 'tenancy from an env placeholder' => [['tenancy' => ['enabled' => '%env(bool:TENANCY)%', 'resolver' => 'x']], 'Expected "bool"'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('refusedConfigurations')]
    public function testConfigurationRefusals(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ApiStudioExtension())->load([$config], new ContainerBuilder());
    }
}
