<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use Nowo\ApiStudioBundle\Script\Runner\ApiStudioScriptRunnerInterface;
use Nowo\ApiStudioBundle\Script\Runner\NullScriptRunner;
use Nowo\ApiStudioBundle\Script\Runner\ProcessScriptRunner;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Service\WorkspaceEnvironmentInitializer;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ApiStudioExtensionTest extends TestCase
{
    public function testDefaultConfigurationIncludesSecureDefaults(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([], $container);

        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_api_studio.ui.required_roles'));
        self::assertSame([], $container->getParameter('nowo_api_studio.execution_url_allowlist'));
        self::assertSame('en|es|fr|it', $container->getParameter('nowo_api_studio.ui.locales_requirement'));
        self::assertTrue($container->getParameter('nowo_api_studio.scripts.confirm_stored'));
        self::assertSame(2000, $container->getParameter('nowo_api_studio.scripts.limits.time_ms'));
    }

    public function testSandboxModeAndTimeLimit(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([['scripts' => ['mode' => 'sandbox', 'limits' => ['time_ms' => 500]]]], $container);

        self::assertSame('sandbox', $container->getParameter('nowo_api_studio.scripts.mode'));
        self::assertSame(500, $container->getParameter('nowo_api_studio.scripts.limits.time_ms'));
    }

    public function testServerRunnerIsOffByDefault(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([], $container);

        self::assertFalse($container->getParameter('nowo_api_studio.scripts.server_runner.enabled'));
        self::assertSame(NullScriptRunner::class, (string) $container->getAlias(ApiStudioScriptRunnerInterface::class));
        self::assertSame(10, $container->getParameter('nowo_api_studio.scripts.limits.send_requests'));
        self::assertSame(64, $container->getParameter('nowo_api_studio.scripts.limits.memory_mb'));
        self::assertSame(256, $container->getParameter('nowo_api_studio.scripts.limits.output_kb'));
    }

    public function testServerRunnerUsesTheBundledSidecar(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([['scripts' => ['server_runner' => ['enabled' => true, 'command' => ['node', '{runner}']], 'limits' => ['send_requests' => 3]]]], $container);

        self::assertSame(ProcessScriptRunner::class, (string) $container->getAlias(ApiStudioScriptRunnerInterface::class));
        $definition = $container->getDefinition(ProcessScriptRunner::class);
        self::assertSame(['node', '{runner}'], $definition->getArgument('$command'));
        self::assertFileExists((string) $definition->getArgument('$runnerPath'));
        self::assertSame(3, $container->getParameter('nowo_api_studio.scripts.limits.send_requests'));
    }

    public function testStoredScriptConfirmationCanBeTurnedOff(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([['scripts' => ['confirm_stored' => false]]], $container);

        self::assertFalse($container->getParameter('nowo_api_studio.scripts.confirm_stored'));
        self::assertSame('inline', $container->getParameter('nowo_api_studio.scripts.mode'));
    }

    public function testHeaderActionsDefaultToTheHeaderRowAndAcceptPageActions(): void
    {
        $default = new ContainerBuilder();
        (new ApiStudioExtension())->load([], $default);
        self::assertSame('header', $default->getParameter('nowo_api_studio.ui.header_actions'));

        $menu = new ContainerBuilder();
        (new ApiStudioExtension())->load([['ui' => ['header_actions' => 'page_actions']]], $menu);
        self::assertSame('page_actions', $menu->getParameter('nowo_api_studio.ui.header_actions'));
        self::assertSame('%nowo_api_studio.ui.header_actions%', $menu->getDefinition(ApiStudioPages::class)->getArgument('$headerActions'));
    }

    public function testAnUnknownHeaderActionsModeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('header_actions');

        (new ApiStudioExtension())->load([['ui' => ['header_actions' => 'toolbar']]], new ContainerBuilder());
    }

    public function testLocalesRequirementReflectsConfiguredLocales(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([
            [
                'ui' => [
                    'locales' => ['en', 'es', 'ca'],
                ],
            ],
        ], $container);

        self::assertSame('en|es|ca', $container->getParameter('nowo_api_studio.ui.locales_requirement'));
    }

    public function testEnvironmentsAreWiredIntoWorkspaceInitializer(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([['environments' => ['local', 'prod']]], $container);

        self::assertSame(['local', 'prod'], $container->getParameter('nowo_api_studio.environments'));
        self::assertSame(
            '%nowo_api_studio.environments%',
            $container->getDefinition(WorkspaceEnvironmentInitializer::class)->getArgument('$defaultEnvironmentNames'),
        );
    }

    public function testHostSecretSourceIsOptIn(): void
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([], $container);
        self::assertNull($container->getDefinition(HostSecretResolver::class)->getArgument('$source'), 'no source: references stay plain values (1.12 behaviour)');

        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([['secrets' => ['source' => 'app.vault_secret_source']]], $container);
        $source = $container->getDefinition(HostSecretResolver::class)->getArgument('$source');
        self::assertInstanceOf(Reference::class, $source);
        self::assertSame('app.vault_secret_source', (string) $source);
    }

    public function testConfigurationAlias(): void
    {
        self::assertSame('nowo_api_studio', (new Configuration())->getConfigTreeBuilder()->buildTree()->getName());
    }
}
