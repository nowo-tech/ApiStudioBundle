<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection\Compiler;

use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;

/**
 * Enforces SecurityBundle for Api Studio UI, allowlist policy, non-empty access roles, form CSRF protection and the
 * access subscriber (REQ-UI-002 / REQ-SEC-005).
 *
 * The access subscriber is always registered (never skipped): with {@code enabled: false} it makes every
 * Api Studio route answer 404 instead of leaving the UI and the outbound executor reachable without checks.
 */
final class ApiStudioSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(Configuration::ALIAS . '.enabled')) {
            return;
        }

        $enabled              = (bool) $container->getParameter(Configuration::ALIAS . '.enabled');
        $allowUnauthenticated = (bool) $container->getParameter(Configuration::ALIAS . '.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');

        if ($enabled) {
            $this->assertConfiguration($container, $allowUnauthenticated, $hasSecurity);
        }

        if ($container->hasDefinition(ApiStudioAccessSubscriber::class)) {
            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $allowUnauthenticated || !$hasSecurity
            ? []
            : $container->getParameter(Configuration::ALIAS . '.security.effective_access_roles');

        $definition = $container->register(ApiStudioAccessSubscriber::class, ApiStudioAccessSubscriber::class)
            ->setArgument('$requiredRoles', $accessRoles)
            ->setArgument('$authorizationChecker', $hasSecurity ? new Reference('security.authorization_checker') : null)
            ->setArgument('$enabled', $enabled)
            ->setArgument('$allowUnauthenticated', $allowUnauthenticated)
            ->addTag('kernel.event_subscriber');

        if ($enabled && self::hasCustomAccessChecker($container)) {
            $definition
                ->setArgument('$accessChecker', new Reference(ApiStudioAccessCheckerInterface::class))
                ->setArgument('$tokenStorage', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
    }

    private function assertConfiguration(ContainerBuilder $container, bool $allowUnauthenticated, bool $hasSecurity): void
    {
        /** @var list<string> $allowlist */
        $allowlist         = $container->getParameter(Configuration::ALIAS . '.execution_url_allowlist');
        $allowlistRequired = (bool) $container->getParameter(Configuration::ALIAS . '.execution_url_allowlist_required');
        if ($allowlistRequired && $allowlist === []) {
            throw new InvalidConfigurationException('nowo_api_studio.execution_url_allowlist_required is true but execution_url_allowlist is empty. Add host patterns (or set execution_url_allowlist_required: false for local demos only).');
        }

        if (!$hasSecurity && !$allowUnauthenticated) {
            throw new InvalidConfigurationException('nowo_api_studio requires symfony/security-bundle (security.authorization_checker), or set nowo_api_studio.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        if (
            !$allowUnauthenticated
            && !self::hasCustomAccessChecker($container)
            && $container->getParameter(Configuration::ALIAS . '.security.effective_access_roles') === []
        ) {
            throw new InvalidConfigurationException('nowo_api_studio: security.access_roles and the legacy ui.required_roles are both empty, so nobody could use Api Studio (an empty list denies). Configure at least one role, set security.access_checker, or set security.allow_unauthenticated: true (dev/demo only).');
        }

        self::assertCsrfProtection($container);
    }

    /**
     * Delete actions and entity forms rely on Symfony's form CSRF extension; without it they would accept forged
     * POSTs, so the container refuses to compile instead (fail closed).
     */
    private static function assertCsrfProtection(ContainerBuilder $container): void
    {
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new InvalidConfigurationException('nowo_api_studio (enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection, or set nowo_api_studio.enabled: false.');
        }
    }

    private static function hasCustomAccessChecker(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter(Configuration::ALIAS . '.security')) {
            return false;
        }

        $security = $container->getParameter(Configuration::ALIAS . '.security');

        return is_array($security) && is_string($security['access_checker'] ?? null) && $security['access_checker'] !== '';
    }
}
