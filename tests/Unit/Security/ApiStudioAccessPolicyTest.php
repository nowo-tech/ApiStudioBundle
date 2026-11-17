<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

final class ApiStudioAccessPolicyTest extends TestCase
{
    public function testSubscribedEventsRunBeforeTheFirewallAndAfterTheSessionListener(): void
    {
        self::assertSame(
            [
                KernelEvents::EXCEPTION => ['onKernelException', 16],
                KernelEvents::RESPONSE  => ['onKernelResponse', -2048],
            ],
            ApiStudioAccessPolicy::getSubscribedEvents(),
        );
    }

    public function testOnlyForbiddenOrNotFoundAreAccepted(): void
    {
        self::assertFalse((new ApiStudioAccessPolicy())->hidesDenials());
        self::assertTrue((new ApiStudioAccessPolicy(404))->hidesDenials());

        $this->expectException(InvalidArgumentException::class);
        new ApiStudioAccessPolicy(401);
    }

    public function testTheServiceKeepsNoRequestState(): void
    {
        self::assertTrue((new ReflectionClass(ApiStudioAccessPolicy::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(ApiStudioRoutes::class))->isReadOnly());
    }

    public function testDeniedJsonIsForbiddenOrTheRoutesOwnNotFound(): void
    {
        $forbidden = (new ApiStudioAccessPolicy())->deniedJson('Endpoint not found.');
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $forbidden->getContent());

        $hidden = (new ApiStudioAccessPolicy(404))->deniedJson('Endpoint not found.');
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame('{"error":"Endpoint not found."}', $hidden->getContent());
    }

    /**
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function throwables(): iterable
    {
        yield 'access denied http' => [new AccessDeniedHttpException('Api Studio: "workspace.manage" is not allowed.'), true];
        yield 'not found' => [new NotFoundHttpException('Workspace not found.'), true];
        yield 'http 403' => [new HttpException(403, 'plan', null, ['X-Plan' => 'free']), true];
        yield 'http 422' => [new HttpException(422), false];
        yield 'server error' => [new RuntimeException('boom'), false];
    }

    #[DataProvider('throwables')]
    public function testRefusalsOfStudioRoutesBecomeTheSameNotFound(Throwable $throwable, bool $converted): void
    {
        $logger = new InMemoryLogger();
        $event  = $this->exceptionEvent('nowo_api_studio_workspace_edit', $throwable);
        (new ApiStudioAccessPolicy(404, logger: $logger))->onKernelException($event);

        if (!$converted) {
            self::assertSame($throwable, $event->getThrowable());
            self::assertSame([], $logger->records);

            return;
        }

        $replaced = $event->getThrowable();
        self::assertInstanceOf(NotFoundHttpException::class, $replaced);
        self::assertSame(ApiStudioAccessPolicy::NOT_FOUND_MESSAGE, $replaced->getMessage());
        self::assertSame([], $replaced->getHeaders());
        self::assertNull($replaced->getPrevious(), 'the firewall walks the chain: no previous');
        self::assertCount(1, $logger->records);
    }

    public function testTheDefaultPolicyAndOtherRoutesAreUntouched(): void
    {
        $denied = new AccessDeniedHttpException();
        $event  = $this->exceptionEvent('nowo_api_studio_workspace_edit', $denied);
        (new ApiStudioAccessPolicy())->onKernelException($event);
        self::assertSame($denied, $event->getThrowable());

        $event = $this->exceptionEvent('app_home', $denied);
        (new ApiStudioAccessPolicy(404))->onKernelException($event);
        self::assertSame($denied, $event->getThrowable());

        $event = $this->exceptionEvent('app_share', $denied);
        (new ApiStudioAccessPolicy(404, new ApiStudioRoutes(['app_share' => 'studio.open'])))->onKernelException($event);
        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable(), 'a declared host route');
    }

    public function testSecurityRefusalsOfASignedInUserAreHiddenAnonymousOnesKeepTheFirewall(): void
    {
        $tokens = new TokenStorage();
        $policy = new ApiStudioAccessPolicy(404, tokenStorage: $tokens);
        $denied = new AccessDeniedException();

        $event = $this->exceptionEvent('nowo_api_studio_dashboard', $denied);
        $policy->onKernelException($event);
        self::assertSame($denied, $event->getThrowable(), 'anonymous: the entry point answers');

        $event = $this->exceptionEvent('nowo_api_studio_dashboard', $denied);
        (new ApiStudioAccessPolicy(404))->onKernelException($event);
        self::assertSame($denied, $event->getThrowable(), 'no token storage: nobody is signed in');

        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        $event = $this->exceptionEvent('nowo_api_studio_dashboard', $denied);
        $policy->onKernelException($event);
        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
    }

    public function testNotFoundAnswersOfStudioRoutesShareTheirCacheHeaders(): void
    {
        $policy = new ApiStudioAccessPolicy(404);
        foreach ([['nowo_api_studio_dashboard', 404, 'no-store, private'], ['nowo_api_studio_dashboard', 200, 'max-age=0, must-revalidate, private'], ['app_home', 404, 'max-age=0, must-revalidate, private']] as [$route, $status, $expected]) {
            $response = $this->sessionResponse($status);
            $policy->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: ['_route' => $route]), HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertSame($expected, $response->headers->get('Cache-Control'), $route . ' ' . $status);
            self::assertSame($expected === 'no-store, private', !$response->headers->has('Expires'));
        }

        $response = $this->sessionResponse(404);
        (new ApiStudioAccessPolicy())->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: ['_route' => 'nowo_api_studio_dashboard']), HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('max-age=0, must-revalidate, private', $response->headers->get('Cache-Control'), 'the 403 policy changes nothing');
    }

    private function sessionResponse(int $status): Response
    {
        $response = new Response('', $status);
        // As the session listener leaves a response when the session was read.
        $response->setPrivate()->setMaxAge(0)->headers->addCacheControlDirective('must-revalidate');
        $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');

        return $response;
    }

    private function exceptionEvent(string $route, Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(attributes: ['_route' => $route]), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
