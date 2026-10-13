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
 * Enforces SecurityBundle for Api Studio UI, allowlist policy, and access subscriber (REQ-UI-002 / REQ-SEC).
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
