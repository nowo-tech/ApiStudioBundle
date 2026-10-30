<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventSubscriber;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ApiStudioAccessSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        self::assertSame(
            [
                KernelEvents::REQUEST    => ['onKernelRequest', 30],
                KernelEvents::CONTROLLER => ['onKernelController', 0],
                KernelEvents::RESPONSE   => ['onKernelResponse', 0],
            ],
            ApiStudioAccessSubscriber::getSubscribedEvents(),
        );
    }

    public function testApiStudioResponsesAreNeverCachedOthersAreUntouched(): void
    {
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $subscriber = new ApiStudioAccessSubscriber(['ROLE_ADMIN']);

        foreach (['nowo_api_studio_endpoint_show' => 'no-store, private', 'app_home' => null] as $route => $expected) {
            $request = new Request();
            $request->attributes->set('_route', $route);
            $response = new Response();
            $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

            if ($expected === null) {
                self::assertFalse($response->headers->has('Pragma'), $route);
            } else {
                self::assertSame($expected, $response->headers->get('Cache-Control'), $route);
                self::assertSame('no-cache', $response->headers->get('Pragma'));
            }
        }
    }

    public function testDisabledBundleAnswers404OnRequest(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ApiStudioAccessSubscriber([], null, false))->onKernelRequest($this->requestEvent('nowo_api_studio_execute'));
    }

    public function testDisabledBundleIgnoresHostRoutes(): void
    {
        (new ApiStudioAccessSubscriber([], null, false))->onKernelRequest($this->requestEvent('app_home'));
        (new ApiStudioAccessSubscriber([], null, false))->onKernelController($this->controllerEvent('app_home'));

        self::addToAssertionCount(1);
    }

    public function testEnabledBundleDoesNothingOnRequest(): void
    {
        (new ApiStudioAccessSubscriber([]))->onKernelRequest($this->requestEvent('nowo_api_studio_dashboard'));

        self::addToAssertionCount(1);
    }

    public function testDisabledBundleAnswers404OnController(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ApiStudioAccessSubscriber([], null, false))->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));
    }

    public function testDeniesWhenNoRequiredRoleGranted(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        (new ApiStudioAccessSubscriber(['ROLE_ADMIN'], $auth))->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));
    }

    public function testAllowsWhenRoleGrantedAndNoChecker(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);

        (new ApiStudioAccessSubscriber(['ROLE_ADMIN'], $auth))->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));

        self::addToAssertionCount(1);
    }

    public function testEmptyRequiredRolesDenyWithoutCheckerOrUnauthenticatedFlag(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('an empty role list denies');

        (new ApiStudioAccessSubscriber([], $auth))->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));
    }

    public function testEmptyRequiredRolesAllowedOnlyWithUnauthenticatedFlag(): void
    {
        (new ApiStudioAccessSubscriber([], null, true, null, null, true))
            ->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));

        self::addToAssertionCount(1);
    }

    public function testRolesWithoutAuthorizationCheckerDeny(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new ApiStudioAccessSubscriber(['ROLE_ADMIN']))->onKernelController($this->controllerEvent('nowo_api_studio_dashboard'));
    }

    public function testCustomAccessCheckerIsCalledWithCurrentUser(): void
    {
        $user    = new InMemoryUser('alice', null, ['ROLE_ADMIN']);
        $checker = $this->createMock(ApiStudioAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with($user)->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        (new ApiStudioAccessSubscriber([], null, true, $checker, $this->tokenStorage($user)))
            ->onKernelController($this->controllerEvent('nowo_api_studio_execute'));
    }

    public function testCustomAccessCheckerGrantsAccess(): void
    {
        $checker = $this->createMock(ApiStudioAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);

        (new ApiStudioAccessSubscriber([], null, true, $checker, $this->tokenStorage(new InMemoryUser('bob', null))))
            ->onKernelController($this->controllerEvent('nowo_api_studio_execute'));

        self::addToAssertionCount(1);
    }

    public function testCustomAccessCheckerDeniesAnonymous(): void
    {
        $checker = $this->createMock(ApiStudioAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $this->expectException(AccessDeniedException::class);

        (new ApiStudioAccessSubscriber([], null, true, $checker, new TokenStorage()))
            ->onKernelController($this->controllerEvent('nowo_api_studio_execute'));
    }

    public function testCustomAccessCheckerAllowsAnonymousOnlyWhenUnauthenticatedAllowed(): void
    {
        $checker = $this->createMock(ApiStudioAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        (new ApiStudioAccessSubscriber([], null, true, $checker, null, true))
            ->onKernelController($this->controllerEvent('nowo_api_studio_execute'));

        self::addToAssertionCount(1);
    }

    private function tokenStorage(InMemoryUser $user): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }

    private function requestEvent(string $route): RequestEvent
    {
        $request = Request::create('/api-studio');
        $request->attributes->set('_route', $route);

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function controllerEvent(string $route): ControllerEvent
    {
        $request = Request::create('/api-studio');
        $request->attributes->set('_route', $route);

        return new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn (): string => 'ok', $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
