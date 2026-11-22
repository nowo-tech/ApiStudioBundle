<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\ScopeListener;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiWorkspaceAccess;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeeder;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.17: `nowo_api_studio.scope` and `nowo_api_studio.concurrency`.
 */
final class ScopeConfigTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter('nowo_api_studio.scope.enabled'));
        self::assertNull($container->getParameter('nowo_api_studio.scope.path'));
        self::assertSame([], $container->getParameter('nowo_api_studio.scope.path_placeholders'));
        self::assertFalse($container->getDefinition(TablePrefixSubscriber::class)->getArgument('$scopes'));
        self::assertFalse($container->getDefinition(ScopeContext::class)->getArgument('$enabled'));
        self::assertNull($container->getDefinition(ScopeListener::class)->getArgument('$resolver'));
        self::assertNull($container->getDefinition(ApiStudioAuthorization::class)->getArgument('$scopeChecker'));
        self::assertNull($container->getDefinition(ApiWorkspaceAccess::class)->getArgument('$scopeChecker'));
        self::assertFalse($container->getDefinition(ApiStudioUrlGenerator::class)->getArgument('$scopedRoutes'));
        self::assertFalse($container->getDefinition(WorkspaceSeeder::class)->getArgument('$scopes'));
        self::assertFalse($container->getDefinition(TenantSqlFilterListener::class)->getArgument('$enabled'));
        $policy = $container->getDefinition('nowo_api_studio.conflict_response_policy');
        self::assertSame([409, 1], $policy->getArguments());
        self::assertSame('nowo_api_studio.conflict_response_policy', (string) $container->getDefinition(ConcurrencyConflictSubscriber::class)->getArgument('$policy'));
    }

    public function testEnabledScopesAreWired(): void
    {
        $container = $this->load([
            'scope' => [
                'enabled'           => true,
                'resolver'          => 'app.scope_resolver',
                'access_checker'    => 'app.scope_checker',
                'label_provider'    => 'app.scope_labels',
                'path'              => '/orgs/{org}/projects/{project}/api-studio',
                'path_requirements' => ['project' => '[0-9a-f-]{36}'],
                'layout_template'   => 'project/layout.html.twig',
                'tenant_list'       => 'unscoped',
            ],
            'secrets'     => ['source' => 'app.secrets'],
            'concurrency' => ['conflict_status' => 503, 'retry_after' => 4],
        ]);

        self::assertTrue($container->getParameter('nowo_api_studio.scope.enabled'));
        self::assertSame(['org', 'project'], $container->getParameter('nowo_api_studio.scope.path_placeholders'));
        self::assertSame(['project' => '[0-9a-f-]{36}'], $container->getParameter('nowo_api_studio.scope.path_requirements'));
        self::assertSame('project/layout.html.twig', $container->getParameter('nowo_api_studio.scope.layout_template'));
        self::assertSame('unscoped', $container->getParameter('nowo_api_studio.scope.tenant_list'));
        self::assertTrue($container->getDefinition(TablePrefixSubscriber::class)->getArgument('$scopes'));
        self::assertSame('app.scope_resolver', (string) $container->getDefinition(ScopeListener::class)->getArgument('$resolver'));
        self::assertSame('app.scope_checker', (string) $container->getDefinition(ApiStudioAuthorization::class)->getArgument('$scopeChecker'));
        self::assertSame('app.scope_checker', (string) $container->getDefinition(ApiWorkspaceAccess::class)->getArgument('$scopeChecker'));
        self::assertFalse($container->getDefinition(ApiStudioAuthorization::class)->getArgument('$tenantListAll'));
        self::assertSame('app.scope_labels', (string) $container->getDefinition(ApiStudioPageFactory::class)->getArgument('$scopeLabels'));
        self::assertTrue($container->getDefinition(ApiStudioUrlGenerator::class)->getArgument('$scopedRoutes'));
        self::assertTrue($container->getDefinition(WorkspaceSeeder::class)->getArgument('$secretSource'));
        self::assertFalse($container->getDefinition(WorkspaceSeeder::class)->getArgument('$tenancy'));
        self::assertTrue($container->getDefinition(WorkspaceScopeMover::class)->getArgument('$scopes'));
        // The SQL filter narrows by scope even without tenancy.
        self::assertTrue($container->getDefinition(TenantSqlFilterListener::class)->getArgument('$enabled'));
        self::assertSame([503, 4], $container->getDefinition('nowo_api_studio.conflict_response_policy')->getArguments());
    }

    public function testScopesWithoutAScopedMount(): void
    {
        $container = $this->load(['scope' => ['enabled' => true, 'resolver' => 'r', 'access_checker' => 'c']]);

        self::assertNull($container->getParameter('nowo_api_studio.scope.path'));
        self::assertNull($container->getDefinition(ApiStudioPageFactory::class)->getArgument('$scopeLabels'));
        self::assertFalse($container->getDefinition(ApiStudioUrlGenerator::class)->getArgument('$scopedRoutes'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedConfigs(): iterable
    {
        yield 'no resolver' => [['enabled' => true, 'access_checker' => 'c'], 'requires nowo_api_studio.scope.resolver'];
        yield 'no checker' => [['enabled' => true, 'resolver' => 'r'], 'denied by default'];
        yield 'options without enabled' => [['path' => '/p/{project}/api-studio'], 'need scope.enabled: true'];
        yield 'requirements without enabled' => [['path_requirements' => ['p' => 'x']], 'need scope.enabled: true'];
        yield 'same path as ui.path' => [['enabled' => true, 'resolver' => 'r', 'access_checker' => 'c', 'path' => '/api-studio/'], 'differ from nowo_api_studio.ui.path'];
        yield 'relative path' => [['enabled' => true, 'resolver' => 'r', 'access_checker' => 'c', 'path' => 'p/api-studio'], 'must start with'];
        yield 'reserved placeholder' => [['enabled' => true, 'resolver' => 'r', 'access_checker' => 'c', 'path' => '/p/{id}/api-studio'], 'scope.path: the placeholder "{id}"'];
        yield 'unknown requirement' => [['enabled' => true, 'resolver' => 'r', 'access_checker' => 'c', 'path' => '/p/{project}/api-studio', 'path_requirements' => ['org' => 'x']], 'not a placeholder of scope.path'];
        yield 'other tenant list' => [['tenant_list' => 'some'], 'is not allowed'];
        yield 'string switch' => [['enabled' => 'true'], 'Invalid type'];
    }

    /**
     * @param array<string, mixed> $scope
     */
    #[DataProvider('refusedConfigs')]
    public function testRefusedConfigurations(array $scope, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(['scope' => $scope]);
    }

    public function testAConflictStatusOtherThan409Or503IsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['concurrency' => ['conflict_status' => 500]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'prod');
        (new ApiStudioExtension())->load([$config], $container);

        return $container;
    }
}
