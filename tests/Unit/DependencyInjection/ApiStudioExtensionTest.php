<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ApiStudioExtensionTest extends TestCase
{
    public function testDefaultConfigurationIncludesSecureDefaults(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioExtension())->load([], $container);

        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_api_studio.ui.required_roles'));
        self::assertSame([], $container->getParameter('nowo_api_studio.execution_url_allowlist'));
        self::assertSame('en|es|fr|it', $container->getParameter('nowo_api_studio.ui.locales_requirement'));
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

    public function testConfigurationAlias(): void
    {
        self::assertSame('nowo_api_studio', (new Configuration())->getConfigTreeBuilder()->buildTree()->getName());
    }
}
