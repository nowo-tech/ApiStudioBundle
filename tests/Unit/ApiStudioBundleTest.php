<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ApiStudioBundleTest extends TestCase
{
    public function testTranslationDomainConstant(): void
    {
        self::assertSame('NowoApiStudioBundle', ApiStudioBundle::TRANSLATION_DOMAIN);
    }

    public function testBuildRegistersTwigPathsCompilerPass(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new ApiStudioBundle();
        $bundle->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getBeforeOptimizationPasses());
    }

    public function testGetContainerExtensionReturnsApiStudioExtension(): void
    {
        $bundle = new ApiStudioBundle();

        self::assertSame('nowo_api_studio', $bundle->getContainerExtension()->getAlias());
    }
}
