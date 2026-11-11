<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection\Compiler;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function dirname;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * `nowo_api_studio.entity_manager` (1.12, architecture bar rule 6): maps the Api Studio entities on that manager only.
 *
 * The extension prepends nothing into the ORM configuration in that case; this pass adds the attribute driver and the
 * tenant SQL filter to the configured manager, removes the bundle namespace from every other manager's driver chain
 * (DoctrineBundle's `auto_mapping`, the Flex default on the default manager, would map it there too and
 * `getManagerForClass()` would answer the default manager), and fails the build when the manager does not exist.
 * Without the option nothing changes (the 1.x prepended mapping on the default manager).
 */
final class ApiStudioEntityManagerPass implements CompilerPassInterface
{
    public const ENTITY_NAMESPACE = 'Nowo\\ApiStudioBundle\\Entity';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ApiStudioExtension::ENTITY_MANAGER_PARAMETER)) {
            return;
        }

        $name = $container->getParameter(ApiStudioExtension::ENTITY_MANAGER_PARAMETER);
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_api_studio.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            foreach ($chain->getMethodCalls() as $call) {
                if ($call[0] === 'addDriver' && is_string($call[1][1] ?? null) && str_starts_with($call[1][1], self::ENTITY_NAMESPACE)) {
                    continue;
                }

                $calls[] = $call;
            }

            if ($manager === $name) {
                $calls[] = ['addDriver', [new Definition(AttributeDriver::class, [[dirname(__DIR__, 2) . '/Entity'], true]), self::ENTITY_NAMESPACE]];
            }

            $chain->setMethodCalls($calls);
        }

        // The tenant filter on the bundle's manager (registered disabled; TenantSqlFilterListener enables it per request).
        $configurationId = sprintf('doctrine.orm.%s_configuration', $name);
        if (!$container->hasDefinition($configurationId)) {
            return;
        }

        $configuration = $container->getDefinition($configurationId);
        foreach ($configuration->getMethodCalls() as $call) {
            if ($call[0] === 'addFilter' && ($call[1][0] ?? null) === TenantSqlFilter::NAME) {
                return;
            }
        }

        $configuration->addMethodCall('addFilter', [TenantSqlFilter::NAME, TenantSqlFilter::class]);
    }
}
