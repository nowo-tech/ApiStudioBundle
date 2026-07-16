<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Nowo\ApiStudioBundle\Security\ConfigurableApiStudioAccessChecker;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ConfigurableApiStudioAccessCheckerTest extends TestCase
{
    public function testAllowsAccessWhenNoRolesConfigured(): void
    {
        $checker = new ConfigurableApiStudioAccessChecker(
            $this->createMock(AuthorizationCheckerInterface::class),
            [],
        );

        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testAllowsAccessWhenUserHasConfiguredRole(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnMap([
            ['ROLE_API_STUDIO', false],
            ['ROLE_ADMIN', true],
        ]);

        $checker = new ConfigurableApiStudioAccessChecker($authorization, ['ROLE_API_STUDIO', 'ROLE_ADMIN']);

        self::assertTrue($checker->canAccess(new stdClass()));
    }

    public function testDeniesAccessWhenNoRoleMatches(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(false);

        $checker = new ConfigurableApiStudioAccessChecker($authorization, ['ROLE_API_STUDIO']);

        self::assertFalse($checker->canAccess(new stdClass()));
    }
}
