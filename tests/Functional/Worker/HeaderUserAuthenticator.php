<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * 1.12.1 (REQ-RUNTIME-002): the worker-state test's stateless login: the user named by the `X-Test-User` header of
 * each request (no session, no secret), loaded from the in-memory provider.
 */
final class HeaderUserAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): bool
    {
        return $request->headers->has('X-Test-User');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        return new SelfValidatingPassport(new UserBadge((string) $request->headers->get('X-Test-User')));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
