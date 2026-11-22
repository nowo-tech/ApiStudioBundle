<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\IdOnlyPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Support\IdOnlyScopeChecker;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.19 (DDD-host checklist B1/B8): the permission subject can be built from ids only, and the studio's own checks pass
 * the actor id, so a host checker decides on ids alone.
 */
final class IdSubjectTest extends TestCase
{
    public function testSubjectFromIds(): void
    {
        $tenant  = new ApiStudioTenant('org', 'acme');
        $subject = ApiStudioSubject::forWorkspaceId($tenant, 7, new WorkspaceScope('project:1'), 'u:alice');

        self::assertSame(7, $subject->workspaceId);
        self::assertNull($subject->workspace);
        self::assertSame('project:1', $subject->scope?->value);
        self::assertSame('u:alice', $subject->actorId);

        $tenantLevel = ApiStudioSubject::forTenant($tenant, null, 'u:alice');
        self::assertNull($tenantLevel->workspaceId);
        self::assertSame('u:alice', $tenantLevel->actorId);
        self::assertNull((new ApiStudioSubject($tenant))->actorId, '1.12 constructor unchanged');
    }

    public function testTheEntityAndAMatchingIdMayBeGivenTogether(): void
    {
        self::assertSame(7, (new ApiStudioSubject(ApiStudioTenant::instance(), $this->workspace(7), null, 7))->workspaceId);
    }

    public function testAnIdThatIsNotTheEntityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiStudioSubject(ApiStudioTenant::instance(), $this->workspace(7), null, 8);
    }

    public function testAnIdForAWorkspaceNotFlushedIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiStudioSubject(ApiStudioTenant::instance(), new ApiWorkspace('Draft', 'draft'), null, 8);
    }

    public function testANonPositiveIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiStudioSubject::forWorkspaceId(ApiStudioTenant::instance(), 0);
    }

    public function testTheStudioPassesTheActorIdToBothCheckers(): void
    {
        $permissions                         = new IdOnlyPermissionChecker();
        $permissions->grants['|u:alice']     = [7 => ['workspace.view']];
        $scopes                              = new IdOnlyScopeChecker();
        $scopes->grants['u:alice|project:1'] = ['workspace.view'];
        $stack                               = new RequestStack([new Request()]);
        $tokens                              = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));

        $authorization = new ApiStudioAuthorization($permissions, null, $tokens, $stack, new ScopeContext($stack, true), $scopes);
        $workspace     = $this->workspace(7)->assignScope(new WorkspaceScope('project:1'));

        self::assertSame('u:alice', $authorization->actorId());
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceView, $workspace));
        self::assertSame('u:alice', $permissions->subjects[0]->actorId);
        self::assertSame(7, $permissions->subjects[0]->workspaceId);
        self::assertSame('u:alice', $scopes->subjects[0]->actorId);
        self::assertSame(7, $scopes->subjects[0]->workspaceId);
    }

    public function testAFailingActorResolverLeavesTheActorIdEmpty(): void
    {
        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'not valid';
            }
        };
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $permissions = new IdOnlyPermissionChecker();

        $authorization = new ApiStudioAuthorization($permissions, null, $tokens, actors: new ApiStudioHistoryScope('user', $resolver));

        self::assertNull($authorization->actorId());
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(7)), 'an id-only checker denies without an actor id');
        self::assertNull((new ApiStudioAuthorization($permissions))->actorId(), 'anonymous');
    }

    public function testAThrowingActorResolverIsLoggedByClassAndLeavesTheActorIdEmpty(): void
    {
        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                throw new RuntimeException('secret detail of the host');
            }
        };
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        $logger = new InMemoryLogger();

        $authorization = new ApiStudioAuthorization(new IdOnlyPermissionChecker(), null, $tokens, actors: new ApiStudioHistoryScope('user', $resolver), logger: $logger);

        self::assertNull($authorization->actorId());
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertStringNotContainsString('secret detail', $logger->text());
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(7)), 'an id-only checker denies without an actor id');
    }

    private function workspace(int $id): ApiWorkspace
    {
        $workspace = new ApiWorkspace('W', 'w');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, $id);

        return $workspace;
    }
}
