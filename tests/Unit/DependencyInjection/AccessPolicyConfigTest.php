<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.16: `security.access_denied_response`, `security.feature_gate` and `security.host_routes`.
 */
final class AccessPolicyConfigTest extends TestCase
{
    public function testDefaultsKeepTheBehaviour(): void
    {
        $container = $this->load([]);

        self::assertSame(403, $container->getParameter('nowo_api_studio.security.access_denied_response'));
        self::assertNull($container->getParameter('nowo_api_studio.security.feature_gate'));
        self::assertSame(403, $container->getDefinition(ApiStudioAccessPolicy::class)->getArgument('$deniedStatus'));
        self::assertSame([], $container->getDefinition(ApiStudioRoutes::class)->getArgument('$hostRoutes'));
        self::assertSame('security.token_storage', (string) $container->getDefinition(ApiStudioAccessPolicy::class)->getArgument('$tokenStorage'));
        self::assertSame('logger', (string) $container->getDefinition(ApiStudioAccessPolicy::class)->getArgument('$logger'));
    }

    public function testPolicyGateAndHostRoutesAreWired(): void
    {
        $container = $this->load(['security' => [
            'access_denied_response' => 404,
            'feature_gate'           => 'app.plan_gate',
            'host_routes'            => ['app_share' => 'studio.open', 'app_import' => 'workspace.create'],
        ]]);

        self::assertSame(404, $container->getParameter('nowo_api_studio.security.access_denied_response'));
        self::assertSame('app.plan_gate', $container->getParameter('nowo_api_studio.security.feature_gate'));
        self::assertSame(404, $container->getDefinition(ApiStudioAccessPolicy::class)->getArgument('$deniedStatus'));
        self::assertSame(['app_share' => 'studio.open', 'app_import' => 'workspace.create'], $container->getDefinition(ApiStudioRoutes::class)->getArgument('$hostRoutes'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedConfigs(): iterable
    {
        yield 'other status' => [['access_denied_response' => 401], 'The value 401 is not allowed'];
        yield 'bundle route' => [['host_routes' => ['nowo_api_studio_dashboard' => 'studio.open']], 'use a route of the host'];
        yield 'workspace action' => [['host_routes' => ['app_share' => 'workspace.manage']], 'use a route of the host'];
        yield 'not a string' => [['host_routes' => ['app_share' => 1]], 'use a route of the host'];
    }

    /**
     * @param array<string, mixed> $security
     */
    #[DataProvider('refusedConfigs')]
    public function testRefusedConfigurations(array $security, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(['security' => $security]);
    }

    public function testAnEnvPlaceholderIsRefused(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'prod');
        $container->registerExtension(new ApiStudioExtension());
        $container->loadFromExtension('nowo_api_studio', ['security' => ['access_denied_response' => '%env(int:API_STUDIO_DENIED)%']]);

        $this->expectException(InvalidConfigurationException::class);

        (new MergeExtensionConfigurationPass())->process($container);
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
