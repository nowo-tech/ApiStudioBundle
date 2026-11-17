<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventSubscriber;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioFeatureGateInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;

/**
 * 1.16 `security.feature_gate` and `security.host_routes` in the access subscriber.
 */
final class FeatureGateTest extends TestCase
{
    /** @var list<array{?object, string, string}> */
    private array $calls = [];

    public function testAClosedGateRefusesAfterStudioOpenAndAnOpenOneLetsThrough(): void
    {
        $tokens                  = $this->signedIn();
        [$authorization, $stack] = $this->authorization($tokens, []);

        $request = $this->request('nowo_api_studio_dashboard', $stack);
        try {
            $this->subscriber($authorization, $this->gate(false))->onKernelController($this->controllerEvent($request));
            self::fail('A closed gate refuses.');
        } catch (AccessDeniedHttpException $e) {
            self::assertSame('Api Studio is not available.', $e->getMessage());
        }
        self::assertCount(1, $this->calls());
        self::assertSame($tokens->getToken()?->getUser(), $this->calls()[0][0]);
        self::assertSame('org:acme', $this->calls()[0][1]);
        self::assertSame('nowo_api_studio_dashboard', $this->calls()[0][2]);

        $this->subscriber($authorization, $this->gate(true))->onKernelController($this->controllerEvent($request));
        self::assertCount(2, $this->calls());
    }

    public function testStudioOpenIsDecidedBeforeTheGate(): void
    {
        [$authorization, $stack] = $this->authorization($this->signedIn(), [ApiStudioAction::StudioOpen]);

        try {
            $this->subscriber($authorization, $this->gate(false))->onKernelController($this->controllerEvent($this->request('nowo_api_studio_dashboard', $stack)));
            self::fail('studio.open denied: 404.');
        } catch (NotFoundHttpException) {
        }
        self::assertSame([], $this->calls(), 'the gate is never asked');
    }

    public function testWorkspaceCreateIsCheckedAfterAnOpenGate(): void
    {
        [$authorization, $stack] = $this->authorization($this->signedIn(), [ApiStudioAction::WorkspaceCreate]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('workspace.create');
        $this->subscriber($authorization, $this->gate(true))->onKernelController($this->controllerEvent($this->request('nowo_api_studio_workspace_new', $stack)));
    }

    public function testWithoutAuthorizationTheGateGetsTheTokenUserAndTheInstanceTenant(): void
    {
        $tokens     = $this->signedIn();
        $subscriber = new ApiStudioAccessSubscriber([], null, true, null, $tokens, true, null, $this->gate(true));
        $subscriber->onKernelController($this->controllerEvent(new Request(attributes: ['_route' => 'nowo_api_studio_not_mapped'])));
        self::assertSame($tokens->getToken()?->getUser(), $this->calls()[0][0]);
        self::assertSame('', $this->calls()[0][1]);

        $subscriber = new ApiStudioAccessSubscriber([], null, true, null, new TokenStorage(), true, null, $this->gate(true));
        $subscriber->onKernelController($this->controllerEvent(new Request(attributes: ['_route' => 'nowo_api_studio_dashboard'])));
        self::assertNull($this->calls()[1][0], 'anonymous');

        $subscriber = new ApiStudioAccessSubscriber([], null, true, null, null, true, null, $this->gate(false));
        $this->expectException(AccessDeniedHttpException::class);
        $subscriber->onKernelController($this->controllerEvent(new Request(attributes: ['_route' => 'nowo_api_studio_dashboard'])));
    }

    public function testHostRoutesAreGuardedLikeTheBundles(): void
    {
        $routes                  = new ApiStudioRoutes(['app_share' => 'studio.open', 'app_import' => 'workspace.create']);
        [$authorization, $stack] = $this->authorization($this->signedIn(), [ApiStudioAction::WorkspaceCreate]);
        $subscriber              = new ApiStudioAccessSubscriber([], null, true, null, null, true, $authorization, $this->gate(true), $routes);

        $subscriber->onKernelController($this->controllerEvent($this->request('app_share', $stack)));
        self::assertSame('app_share', $this->calls()[0][2]);
        $subscriber->onKernelController($this->controllerEvent($this->request('app_home', $stack)));
        self::assertCount(1, $this->calls(), 'a host page that is not declared is not touched');

        $response = new Response();
        $subscriber->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: ['_route' => 'app_share']), HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        try {
            $subscriber->onKernelController($this->controllerEvent($this->request('app_import', $stack)));
            self::fail('workspace.create denied on a host route: 403.');
        } catch (AccessDeniedHttpException) {
        }

        $disabled = new ApiStudioAccessSubscriber([], null, false, routes: $routes);
        $this->expectException(NotFoundHttpException::class);
        $disabled->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: ['_route' => 'app_share']), HttpKernelInterface::MAIN_REQUEST));
    }

    private function subscriber(ApiStudioAuthorization $authorization, ApiStudioFeatureGateInterface $gate): ApiStudioAccessSubscriber
    {
        return new ApiStudioAccessSubscriber([], null, true, null, null, true, $authorization, $gate);
    }

    private function gate(bool $enabled): ApiStudioFeatureGateInterface
    {
        return new class($enabled, $this) implements ApiStudioFeatureGateInterface {
            public function __construct(private readonly bool $enabled, private readonly FeatureGateTest $test)
            {
            }

            public function isEnabled(?object $user, ApiStudioTenant $tenant, Request $request): bool
            {
                $this->test->record($user, $tenant->key(), (string) $request->attributes->get('_route'));

                return $this->enabled;
            }
        };
    }

    public function record(?object $user, string $tenant, string $route): void
    {
        $this->calls[] = [$user, $tenant, $route];
    }

    /**
     * @return list<array{?object, string, string}>
     */
    private function calls(): array
    {
        return $this->calls;
    }

    private function signedIn(): TokenStorage
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        return $tokens;
    }

    /**
     * @param list<ApiStudioAction> $denied
     *
     * @return array{ApiStudioAuthorization, RequestStack}
     */
    private function authorization(TokenStorage $tokens, array $denied): array
    {
        $checker = new class($denied) implements ApiStudioPermissionCheckerInterface {
            /**
             * @param list<ApiStudioAction> $denied
             */
            public function __construct(private readonly array $denied)
            {
            }

            public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
            {
                return !in_array($action, $this->denied, true);
            }

            public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
            {
                return null;
            }
        };
        $stack = new RequestStack();

        return [new ApiStudioAuthorization($checker, new ApiStudioTenantContext($stack, true), $tokens, $stack), $stack];
    }

    private function request(string $route, RequestStack $stack): Request
    {
        $request = new Request(attributes: ['_route' => $route, ApiStudioTenantContext::REQUEST_ATTRIBUTE => new ApiStudioTenant('org', 'acme')]);
        while ($stack->pop() !== null) {
        }
        $stack->push($request);

        return $request;
    }

    private function controllerEvent(Request $request): ControllerEvent
    {
        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
