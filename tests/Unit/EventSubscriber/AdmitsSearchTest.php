<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventSubscriber;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The entry chain of a search (1.18, {@see ApiStudioAccessSubscriber::admitsSearch()}).
 */
final class AdmitsSearchTest extends TestCase
{
    public function testAdmitsSearchRunsTheStudioEntryChain(): void
    {
        $request       = Request::create('/search');
        $authorization = new ApiStudioAuthorization();

        self::assertFalse((new ApiStudioAccessSubscriber([], null, false, null, null, true, $authorization))->admitsSearch($request), 'bundle disabled');
        self::assertFalse((new ApiStudioAccessSubscriber([], null, true, null, null, true))->admitsSearch($request), 'no authorization service');
        self::assertTrue((new ApiStudioAccessSubscriber([], null, true, null, null, true, $authorization))->admitsSearch($request));
        // Roles: an empty list without allow_unauthenticated denies; roles without a checker deny; a missing role denies.
        self::assertFalse((new ApiStudioAccessSubscriber([], null, true, null, null, false, $authorization))->admitsSearch($request));
        self::assertFalse((new ApiStudioAccessSubscriber(['ROLE_API'], null, true, null, null, false, $authorization))->admitsSearch($request));
        self::assertFalse((new ApiStudioAccessSubscriber(['ROLE_API'], $this->roles(false), true, null, null, false, $authorization))->admitsSearch($request));
        self::assertTrue((new ApiStudioAccessSubscriber(['ROLE_API'], $this->roles(true), true, null, null, false, $authorization))->admitsSearch($request));

        // The legacy access checker: anonymous denied, then its own answer.
        $tokens = new TokenStorage();
        self::assertFalse((new ApiStudioAccessSubscriber([], null, true, $this->checker(true), $tokens, false, $authorization))->admitsSearch($request));
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ann', null), 'main'));
        self::assertTrue((new ApiStudioAccessSubscriber([], null, true, $this->checker(true), $tokens, false, $authorization))->admitsSearch($request));
        self::assertFalse((new ApiStudioAccessSubscriber([], null, true, $this->checker(false), $tokens, false, $authorization))->admitsSearch($request));
    }

    private function roles(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function checker(bool $granted): ApiStudioAccessCheckerInterface
    {
        return new class($granted) implements ApiStudioAccessCheckerInterface {
            public function __construct(
                private readonly bool $granted,
            ) {
            }

            public function canAccess(object $user): bool
            {
                return $this->granted;
            }
        };
    }
}
