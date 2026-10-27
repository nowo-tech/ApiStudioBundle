<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioSecurityPass;
use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

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

    public function testDisabledBundleStillRegistersSubscriberThatAnswers404(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.enabled', false);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);
        // Invalid when enabled (no SecurityBundle, empty required allowlist) — must not throw when disabled.
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', []);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', true);

        (new ApiStudioSecurityPass())->process($container);

        $definition = $container->getDefinition(ApiStudioAccessSubscriber::class);
        self::assertFalse($definition->getArgument('$enabled'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
    }

    public function testFailsWithoutSecurityWhenEnabledAndUnauthenticatedNotAllowed(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);

        $this->expectException(InvalidConfigurationException::class);
        (new ApiStudioSecurityPass())->process($container);
    }

    public function testRegistersRoleCheckWithSecurity(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new ApiStudioSecurityPass())->process($container);

        $definition = $container->getDefinition(ApiStudioAccessSubscriber::class);
        self::assertSame(['ROLE_ADMIN'], $definition->getArgument('$requiredRoles'));
        self::assertInstanceOf(Reference::class, $definition->getArgument('$authorizationChecker'));
        self::assertTrue($definition->getArgument('$enabled'));
        self::assertArrayNotHasKey('$accessChecker', $definition->getArguments());
    }

    public function testAllowUnauthenticatedStillRegistersSubscriberWithoutRoles(): void
    {
        $container = $this->baseContainer();

        (new ApiStudioSecurityPass())->process($container);

        self::assertSame([], $container->getDefinition(ApiStudioAccessSubscriber::class)->getArgument('$requiredRoles'));
    }

    public function testWiresCustomAccessChecker(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);
        $container->setParameter(Configuration::ALIAS . '.security', ['access_checker' => 'app.api_studio_access']);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new ApiStudioSecurityPass())->process($container);

        $definition = $container->getDefinition(ApiStudioAccessSubscriber::class);
        self::assertSame(ApiStudioAccessCheckerInterface::class, (string) $definition->getArgument('$accessChecker'));
        self::assertSame('security.token_storage', (string) $definition->getArgument('$tokenStorage'));
    }

    public function testFailsWhenEffectiveRolesEmptyWithoutCheckerOrUnauthenticatedFlag(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);
        $container->setParameter(Configuration::ALIAS . '.security.effective_access_roles', []);
        $container->setDefinition('security.authorization_checker', new Definition());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ui.required_roles are both empty');

        (new ApiStudioSecurityPass())->process($container);
    }

    public function testAcceptsEmptyRolesWithCustomAccessChecker(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', false);
        $container->setParameter(Configuration::ALIAS . '.security.effective_access_roles', []);
        $container->setParameter(Configuration::ALIAS . '.security', ['access_checker' => 'app.api_studio_access']);
        $container->setDefinition('security.authorization_checker', new Definition());

        (new ApiStudioSecurityPass())->process($container);

        self::assertSame([], $container->getDefinition(ApiStudioAccessSubscriber::class)->getArgument('$requiredRoles'));
    }

    public function testFailsWithoutCsrfTokenManager(): void
    {
        $container = $this->baseContainer();
        $container->removeDefinition('security.csrf.token_manager');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new ApiStudioSecurityPass())->process($container);
    }

    public function testFailsWithoutFormCsrfExtension(): void
    {
        $container = $this->baseContainer();
        $container->removeDefinition('form.type_extension.csrf');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new ApiStudioSecurityPass())->process($container);
    }

    public function testFailsWhenFormCsrfDisabled(): void
    {
        $container = $this->baseContainer();
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new ApiStudioSecurityPass())->process($container);
    }

    public function testAcceptsFormCsrfEnabledParameter(): void
    {
        $container = $this->baseContainer();
        $container->setParameter('form.type_extension.csrf.enabled', true);

        (new ApiStudioSecurityPass())->process($container);

        self::assertTrue($container->hasDefinition(ApiStudioAccessSubscriber::class));
    }

    public function testDisabledBundleSkipsCsrfRequirement(): void
    {
        $container = $this->baseContainer();
        $container->setParameter(Configuration::ALIAS . '.enabled', false);
        $container->removeDefinition('security.csrf.token_manager');
        $container->removeDefinition('form.type_extension.csrf');

        (new ApiStudioSecurityPass())->process($container);

        self::assertFalse($container->getDefinition(ApiStudioAccessSubscriber::class)->getArgument('$enabled'));
    }

    public function testKeepsHostDefinedSubscriber(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(ApiStudioAccessSubscriber::class, new Definition('stdClass'));

        (new ApiStudioSecurityPass())->process($container);

        self::assertSame('stdClass', $container->getDefinition(ApiStudioAccessSubscriber::class)->getClass());
    }

    public function testNoopWithoutExtensionParameters(): void
    {
        $container = new ContainerBuilder();

        (new ApiStudioSecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(ApiStudioAccessSubscriber::class));
    }

    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(Configuration::ALIAS . '.enabled', true);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', true);
        $container->setParameter(Configuration::ALIAS . '.security.effective_access_roles', ['ROLE_ADMIN']);
        $container->setParameter(Configuration::ALIAS . '.security', ['access_checker' => null]);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', []);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', false);
        $container->setDefinition('security.csrf.token_manager', new Definition());
        $container->setDefinition('form.type_extension.csrf', new Definition());

        return $container;
    }
}
