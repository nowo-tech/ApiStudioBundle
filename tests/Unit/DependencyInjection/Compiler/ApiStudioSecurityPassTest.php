<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioSecurityPass;
use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ApiStudioSecurityPassTest extends TestCase
{
    public function testFailsWhenAllowlistRequiredAndEmpty(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', []);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', true);

        $this->expectException(InvalidConfigurationException::class);
        (new ApiStudioSecurityPass())->process($container);
    }

    public function testAllowsEmptyAllowlistWhenNotRequired(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', []);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', false);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new ApiStudioSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition('security.authorization_checker'));
    }

    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(Configuration::ALIAS . '.enabled', true);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', true);
        $container->setParameter(Configuration::ALIAS . '.security.effective_access_roles', ['ROLE_ADMIN']);

        return $container;
    }
}
