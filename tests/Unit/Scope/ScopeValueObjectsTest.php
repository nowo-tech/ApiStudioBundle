<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Scope;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\ScopeRouteContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioRouteActions;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function str_repeat;

/**
 * 1.17 scope value objects, the scope context (request attribute, runAs, reset) and the twin route names.
 */
final class ScopeValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['project: a'];
        yield 'trailing space' => ['project:a '];
        yield 'slash' => ['project/a'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'newline' => ["project:a\n"];
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidScopesAreRefusedNotFixed(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkspaceScope($value);
    }

    public function testScopesCompareByteExact(): void
    {
        $scope = new WorkspaceScope('project:0b9c-AB_1.x');
        self::assertSame('project:0b9c-AB_1.x', (string) $scope);
        self::assertTrue($scope->equals(new WorkspaceScope('project:0b9c-AB_1.x')));
        self::assertFalse($scope->equals(new WorkspaceScope('project:0b9c-ab_1.x')));
        self::assertNull(WorkspaceScope::fromStored(''));
        self::assertSame('p:1', WorkspaceScope::fromStored('p:1')?->value);
        self::assertSame('', WorkspaceScope::key(null));
        self::assertSame('p:1', WorkspaceScope::key(new WorkspaceScope('p:1')));
        new WorkspaceScope(str_repeat('a', 128));
    }

    public function testTheRouteContextKeepsScalarRouteParametersOnly(): void
    {
        $context = ScopeRouteContext::fromAttributes('nowo_api_studio_scoped_workspace_show', [
            '_route'  => 'x',
            '_locale' => 'en',
            'org'     => 'acme',
            'id'      => 7,
            'ratio'   => 1.5,
            'flag'    => true,
            'off'     => false,
            'object'  => new stdClass(),
            ''        => 'empty',
            3         => 'numeric',
        ], true);

        self::assertSame(['org' => 'acme', 'id' => '7', 'ratio' => '1.5', 'flag' => '1', 'off' => '0'], $context->parameters);
        self::assertTrue($context->scopedMount);
        self::assertSame('acme', $context->parameter('org'));
        self::assertNull($context->parameter('project'));
    }

    public function testTheContextReadsTheMainRequestAndRunAsRestores(): void
    {
        $request = new Request();
        $stack   = new RequestStack([$request]);
        $context = new ScopeContext($stack, true);
        self::assertTrue($context->isEnabled());
        self::assertNull($context->current());

        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:a'));
        self::assertSame('project:a', self::value($context));

        $context->runAs(new WorkspaceScope('project:b'), static function () use ($context): string {
            self::assertSame('project:b', self::value($context));
            $context->runAs(null, static function () use ($context): void {
                self::assertNull(self::value($context));
            });
            self::assertSame('project:b', self::value($context));

            return (string) self::value($context);
        });
        self::assertSame('project:a', self::value($context));

        try {
            $context->runAs(new WorkspaceScope('project:c'), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        self::assertSame('project:a', self::value($context));
    }

    public function testResetClearsARunAsValueLeftByAFatalPath(): void
    {
        $context = new ScopeContext(null, true);
        $leak    = new ReflectionProperty(ScopeContext::class, 'override');
        $flag    = new ReflectionProperty(ScopeContext::class, 'overridden');
        $leak->setValue($context, new WorkspaceScope('project:a'));
        $flag->setValue($context, true);
        self::assertSame('project:a', self::value($context));

        $context->reset();
        self::assertNull(self::value($context));
    }

    public function testADisabledContextHasNoScope(): void
    {
        $request = new Request();
        $request->attributes->set(ScopeContext::REQUEST_ATTRIBUTE, new WorkspaceScope('project:a'));
        $context = new ScopeContext(new RequestStack([$request]));

        self::assertFalse($context->isEnabled());
        self::assertNull($context->current());
        self::assertNull($context->runAs(new WorkspaceScope('project:b'), static fn (): ?string => self::value($context)));
    }

    public function testTwinRouteNames(): void
    {
        self::assertTrue(ApiStudioRoutes::isScopedMount('nowo_api_studio_scoped_workspace_show'));
        self::assertFalse(ApiStudioRoutes::isScopedMount('nowo_api_studio_workspace_show'));
        self::assertFalse(ApiStudioRoutes::isScopedMount(null));
        self::assertSame('nowo_api_studio_workspace_show', ApiStudioRoutes::canonical('nowo_api_studio_scoped_workspace_show'));
        self::assertSame('app_share', ApiStudioRoutes::canonical('app_share'));
        self::assertSame('nowo_api_studio_scoped_workspace_show', ApiStudioRoutes::scopedName('nowo_api_studio_workspace_show'));
        self::assertSame('nowo_api_studio_scoped_workspace_show', ApiStudioRoutes::scopedName('nowo_api_studio_scoped_workspace_show'));
        self::assertSame('app_share', ApiStudioRoutes::scopedName('app_share'));
        self::assertSame(ApiStudioRouteActions::actionFor('nowo_api_studio_execute'), ApiStudioRouteActions::actionFor('nowo_api_studio_scoped_execute'));
        self::assertNull(ApiStudioRouteActions::actionFor('nowo_api_studio_scoped_unknown'));
    }

    public function testTheRequestViewNarrowsByScope(): void
    {
        $tenant   = new ApiStudioTenant('org', 'acme');
        $scoped   = (new ApiWorkspace('A', 'a'))->assignScope(new WorkspaceScope('project:a'));
        $unscoped = new ApiWorkspace('B', 'b');

        // Scopes off: no narrowing at all.
        self::assertTrue((new ApiStudioScope($tenant))->contains($scoped));

        $inA = new ApiStudioScope($tenant, false, null, new WorkspaceScope('project:a'), true);
        self::assertTrue($inA->contains($scoped));
        self::assertFalse($inA->contains($unscoped));
        self::assertFalse($inA->containsScope('project:A'));

        $tenantAll = new ApiStudioScope($tenant, false, null, null, true, true, null);
        self::assertTrue($tenantAll->contains($scoped));
        self::assertTrue($tenantAll->contains($unscoped));

        $tenantSome = new ApiStudioScope($tenant, false, null, null, true, true, ['project:b']);
        self::assertFalse($tenantSome->contains($scoped));
        self::assertTrue($tenantSome->containsScope('project:b'));

        $tenantUnscoped = new ApiStudioScope($tenant, false, null, null, true, false);
        self::assertFalse($tenantUnscoped->contains($scoped));
        self::assertTrue($tenantUnscoped->contains($unscoped));
    }

    public function testASavedWorkspaceMayChangeScope(): void
    {
        $workspace = new ApiWorkspace('A', 'a');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 5);
        $before = $workspace->getUpdatedAt();

        $workspace->assignScope(new WorkspaceScope('project:a'));
        self::assertSame('project:a', $workspace->getScopeKey());
        self::assertGreaterThanOrEqual($before, $workspace->getUpdatedAt());
        $workspace->assignScope(null);
        self::assertNull($workspace->getScope());
    }

    private static function value(ScopeContext $context): ?string
    {
        return $context->current()?->value;
    }
}
