<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection\Compiler;

use Nowo\ApiStudioBundle\DependencyInjection\Configuration;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Enforces SecurityBundle for Api Studio UI, allowlist policy, and access subscriber (REQ-UI-002 / REQ-SEC).
 */
final class ApiStudioSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(Configuration::ALIAS . '.enabled')) {
            return;
        }
        if (!$container->getParameter(Configuration::ALIAS . '.enabled')) {
            return;
        }

        /** @var list<string> $allowlist */
        $allowlist         = $container->getParameter(Configuration::ALIAS . '.execution_url_allowlist');
        $allowlistRequired = (bool) $container->getParameter(Configuration::ALIAS . '.execution_url_allowlist_required');
        if ($allowlistRequired && $allowlist === []) {
            throw new InvalidConfigurationException('nowo_api_studio.execution_url_allowlist_required is true but execution_url_allowlist is empty. Add host patterns (or set execution_url_allowlist_required: false for local demos only).');
        }

        $allowUnauthenticated = (bool) $container->getParameter(Configuration::ALIAS . '.security.allow_unauthenticated');
        $hasSecurity          = $container->has('security.authorization_checker');

        if (!$hasSecurity && !$allowUnauthenticated) {
            throw new InvalidConfigurationException('nowo_api_studio requires symfony/security-bundle (security.authorization_checker), or set nowo_api_studio.security.allow_unauthenticated: true (dev/demo only — never in production).');
        }

        if ($allowUnauthenticated) {
            return;
        }

        /** @var list<string> $accessRoles */
        $accessRoles = $container->getParameter(Configuration::ALIAS . '.security.effective_access_roles');
        if ($accessRoles === []) {
            return;
        }

        if ($container->hasDefinition(ApiStudioAccessSubscriber::class)) {
            return;
        }

        $container->register(ApiStudioAccessSubscriber::class)
            ->setArgument('$requiredRoles', $accessRoles)
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
            ->addTag('kernel.event_subscriber');
    }
}
