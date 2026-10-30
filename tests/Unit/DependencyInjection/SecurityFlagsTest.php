<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * BW audit L3: security switches are literal booleans (an %env()% placeholder would be read as true at compile time)
 * and allow_unauthenticated is a debug-only switch, never in prod.
 */
final class SecurityFlagsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function placeholderConfigs(): iterable
    {
        yield 'allow_unauthenticated' => [['security' => ['allow_unauthenticated' => '%env(bool:API_STUDIO_OPEN)%']]];
        yield 'enabled' => [['enabled' => '%env(bool:API_STUDIO_ENABLED)%']];
        yield 'execution_url_allowlist_required' => [['execution_url_allowlist_required' => '%env(bool:API_STUDIO_ALLOWLIST)%']];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('placeholderConfigs')]
    public function testEnvPlaceholdersAreRefused(array $config): void
    {
        // As in a kernel: the env placeholder reaches the extension through MergeExtensionConfigurationPass.
        $container = $this->container(true, 'dev');
        $container->registerExtension(new ApiStudioExtension());
        $container->loadFromExtension('nowo_api_studio', $config);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');

        (new MergeExtensionConfigurationPass())->process($container);
    }

    /**
     * @return iterable<string, array{bool, string|null}>
     */
    public static function refusedContexts(): iterable
    {
        yield 'no debug' => [false, 'dev'];
        yield 'prod with debug' => [true, 'prod'];
        yield 'prod' => [false, 'prod'];
        yield 'no kernel parameters' => [false, null];
    }

    #[DataProvider('refusedContexts')]
    public function testAllowUnauthenticatedIsRefusedOutsideDebug(bool $debug, ?string $environment): void
    {
        $container = $environment === null ? new ContainerBuilder() : $this->container($debug, $environment);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('development and demos only');

        (new ApiStudioExtension())->load([['security' => ['allow_unauthenticated' => true]]], $container);
    }

    public function testAllowUnauthenticatedIsAcceptedInDebug(): void
    {
        $container = $this->container(true, 'dev');

        (new ApiStudioExtension())->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertTrue($container->getParameter('nowo_api_studio.security.allow_unauthenticated'));
    }

    public function testLiteralFalseNeedsNoDebug(): void
    {
        $container = $this->container(false, 'prod');

        (new ApiStudioExtension())->load([['enabled' => false, 'security' => ['allow_unauthenticated' => false]]], $container);

        self::assertFalse($container->getParameter('nowo_api_studio.enabled'));
    }

    private function container(bool $debug, string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.environment', $environment);

        return $container;
    }
}
