<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Scope;

use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tests\Support\RecordingPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Support\RecordingScopeAccessChecker;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function range;

/**
 * 1.17: per-scope decisions of ApiStudioAuthorization — the scope checker on top of the permission checker, deny by
 * default without a checker, the request view, memos per scope.
 */
final class ScopeAuthorizationTest extends TestCase
{
    public function testAScopedRequestAsksTheScopeCheckerForTenantLevelAndWorkspaceActions(): void
    {
        [$authorization, $checker, $permissions] = $this->authorization(new WorkspaceScope('project:a'));
        $workspace                               = $this->workspace(7, 'project:a');

        self::assertTrue($authorization->can(ApiStudioAction::StudioOpen));
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceEdit, $workspace));
        // Memoised per request: asking again does not call the checkers again.
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceEdit, $workspace));
        self::assertSame([['studio.open', 'project:a', null], ['workspace.edit', 'project:a', 7]], $checker->seen);
        self::assertSame('project:a', $permissions->subjects[1]->scope?->value);

        $checker->denied = [ApiStudioAction::WorkspaceManage];
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceManage, $workspace));

        // Another scope's workspace is not even asked about.
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(8, 'project:b')));
        self::assertSame(['project:a'], [$authorization->scope()->hostScope?->value]);
    }

    public function testThePermissionCheckerCanStillDeny(): void
    {
        [$authorization, , $permissions] = $this->authorization(new WorkspaceScope('project:a'));
        $permissions->denied             = [ApiStudioAction::WorkspaceView];

        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(7, 'project:a')));
    }

    public function testWithoutAScopeCheckerEveryScopedActionIsDenied(): void
    {
        $request = new Request();
        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:a'));
        $stack         = new RequestStack([$request]);
        $authorization = new ApiStudioAuthorization($this->permissions(), null, null, $stack, new ScopeContext($stack, true));

        self::assertFalse($authorization->can(ApiStudioAction::StudioOpen));

        // Tenant level: scoped workspaces are not listed (visible scopes = none) and not granted.
        $tenantRequest = new Request();
        $tenantStack   = new RequestStack([$tenantRequest]);
        $tenantLevel   = new ApiStudioAuthorization($this->permissions(), null, null, $tenantStack, new ScopeContext($tenantStack, true));
        self::assertSame([], $tenantLevel->scope()->visibleScopes);
        self::assertTrue($tenantLevel->can(ApiStudioAction::StudioOpen));
        self::assertTrue($tenantLevel->can(ApiStudioAction::WorkspaceView, $this->workspace(1, '')));
        self::assertFalse($tenantLevel->can(ApiStudioAction::WorkspaceView, $this->workspace(2, 'project:a')));
    }

    public function testTheTenantLevelViewListsTheVisibleScopes(): void
    {
        [$authorization, $checker] = $this->authorization(null);
        $checker->visible          = ['project:a'];

        $scope = $authorization->scope();
        self::assertTrue($scope->scopes);
        self::assertNull($scope->hostScope);
        self::assertSame(['project:a'], $scope->visibleScopes);
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(1, 'project:a')));
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(2, 'project:b')));
        // Tenant-level actions at tenant level never ask the scope checker.
        self::assertTrue($authorization->can(ApiStudioAction::StudioOpen));
        self::assertSame([['workspace.view', 'project:a', 1]], $checker->seen);
    }

    public function testTenantListUnscopedNeverAsksForVisibleScopes(): void
    {
        $request          = new Request();
        $stack            = new RequestStack([$request]);
        $checker          = $this->scopeChecker();
        $checker->visible = ['project:a'];
        $authorization    = new ApiStudioAuthorization($this->permissions(), null, null, $stack, new ScopeContext($stack, true), $checker, false);

        self::assertNull($authorization->scope()->visibleScopes);
        self::assertFalse($authorization->scope()->tenantListAll);
        self::assertFalse($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(1, 'project:a')));
    }

    public function testTooManyVisibleScopesFailLoudly(): void
    {
        [$authorization, $checker] = $this->authorization(null);
        $checker->visible          = [];
        foreach (range(0, ApiStudioAuthorization::MAX_VISIBLE_IDS) as $i) {
            $checker->visible[] = 'p:' . $i;
        }

        $this->expectException(LogicException::class);
        $authorization->scope();
    }

    public function testARunAsScopeIsNotServedTheMemoOfAnotherScope(): void
    {
        $request       = new Request();
        $stack         = new RequestStack([$request]);
        $context       = new ScopeContext($stack, true);
        $authorization = new ApiStudioAuthorization($this->permissions(), null, null, $stack, $context, $this->scopeChecker());

        self::assertNull($authorization->scope()->hostScope);
        $inside = $context->runAs(new WorkspaceScope('project:x'), static fn (): ApiStudioScope => $authorization->scope());
        self::assertInstanceOf(WorkspaceScope::class, $inside->hostScope);
        self::assertSame('project:x', $inside->hostScope->value);
        self::assertNull($authorization->scope()->hostScope);
    }

    public function testScopesOffChangeNothing(): void
    {
        $stack         = new RequestStack([new Request()]);
        $checker       = $this->scopeChecker();
        $authorization = new ApiStudioAuthorization($this->permissions(), null, null, $stack, new ScopeContext($stack, false), $checker);

        self::assertFalse($authorization->scope()->scopes);
        self::assertNull($authorization->hostScope());
        self::assertTrue($authorization->can(ApiStudioAction::WorkspaceView, $this->workspace(1, 'project:a')));
        self::assertSame([], $checker->seen);
    }

    /**
     * @return array{ApiStudioAuthorization, RecordingScopeAccessChecker, RecordingPermissionChecker}
     */
    private function authorization(?WorkspaceScope $scope): array
    {
        $request = new Request();
        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, $scope);
        $stack       = new RequestStack([$request]);
        $checker     = $this->scopeChecker();
        $permissions = $this->permissions();

        return [new ApiStudioAuthorization($permissions, null, null, $stack, new ScopeContext($stack, true), $checker), $checker, $permissions];
    }

    private function workspace(int $id, string $scope): ApiWorkspace
    {
        $workspace = (new ApiWorkspace('W', 'w'))->assignScope(WorkspaceScope::fromStored($scope));
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, $id);

        return $workspace;
    }

    private function scopeChecker(): RecordingScopeAccessChecker
    {
        return new RecordingScopeAccessChecker();
    }

    private function permissions(): RecordingPermissionChecker
    {
        return new RecordingPermissionChecker();
    }
}
