<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ConfigurableApiStudioAccessChecker;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Loads bundle configuration and registers services.
 */
final class ApiStudioExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    'nowo_api_studio' => [
                        'base_path' => '/bundles/nowoapistudio',
                    ],
                ],
            ],
            'translator' => [
                'paths' => [__DIR__ . '/../Resources/translations'],
            ],
        ]);

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'ApiStudioBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(Configuration::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(Configuration::ALIAS . '.environments', $config['environments']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.ui.path', $config['ui']['path']);
        $container->setParameter(Configuration::ALIAS . '.ui.default_locale', $config['ui']['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.ui.locales', $config['ui']['locales']);
        $container->setParameter(
            Configuration::ALIAS . '.ui.locales_requirement',
            implode('|', $config['ui']['locales']),
        );
        $container->setParameter(Configuration::ALIAS . '.ui.request_timeout_seconds', $config['ui']['request_timeout_seconds']);
        $container->setParameter(Configuration::ALIAS . '.ui.required_roles', $config['ui']['required_roles']);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', $config['execution_url_allowlist']);
        $container->setParameter(Configuration::ALIAS . '.security', $config['security']);

        $container->register(ExecutionUrlValidator::class)
            ->setArgument('$allowlist', $config['execution_url_allowlist']);

        $this->registerAccessChecker($container, $config['security'], $config['ui']['required_roles']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<string, mixed> $security @param list<string> $legacyRequiredRoles */
    private function registerAccessChecker(ContainerBuilder $container, array $security, array $legacyRequiredRoles): void
    {
        $accessRoles = $security['access_roles'] !== [] ? $security['access_roles'] : $legacyRequiredRoles;

        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_api_studio.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableApiStudioAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $accessRoles));
        }

        $container->setAlias(ApiStudioAccessCheckerInterface::class, $accessCheckerId);

        if ($accessRoles !== [] && $container->has('security.authorization_checker')) {
            $container->register(ApiStudioAccessSubscriber::class)
                ->setArgument('$requiredRoles', $accessRoles)
                ->setArgument('$authorizationChecker', new Reference('security.authorization_checker'))
                ->addTag('kernel.event_subscriber');
        }
    }
}
