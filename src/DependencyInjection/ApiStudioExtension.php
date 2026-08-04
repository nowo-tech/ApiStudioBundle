<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection;

use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ConfigurableApiStudioAccessChecker;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Loads bundle configuration and registers services.
 */
final class ApiStudioExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => [
                    'packages' => [
                        Configuration::ALIAS => [
                            'base_path' => '/bundles/apistudio',
                        ],
                    ],
                ],
                'translator' => [
                    'paths' => [__DIR__ . '/../Resources/translations'],
                ],
            ]);
        }

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

        $this->prependUiKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the {@code api_studio} profile (labels domain +
     * Bootstrap/UiKit-friendly field classes). Forms select it via {@code #[FormKitConfig]}.
     * Does not change the host {@code default_profile}.
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('api_studio', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
            $ui     = $config['ui'];
            $fw     = (string) ($ui['css_framework'] ?? 'custom');
            // FormKit accepts only bootstrap|tailwind|foundation|none (not bootstrap5 / custom / …).
            $seed['css_framework'] = match ($fw) {
                'tailwind'   => 'tailwind',
                'foundation' => 'foundation',
                default      => 'bootstrap',
            };
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'api_studio' => [
                    'alias'              => 'api_studio',
                    'translation_domain' => 'NowoApiStudioBundle',
                    'defaults'           => [
                        'attr'     => ['class' => 'nowo-ui-input form-control'],
                        'row_attr' => ['class' => 'mb-2'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check mb-2'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from
     * ui.css_framework so kit macros resolve the same stack.
     * Does not override keys the host already set under nowo_ui_kit.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config   = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $ui       = is_array($config['ui'] ?? null) ? $config['ui'] : [];
        $defaults = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($ui['css_framework'] ?? 'custom');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fw                   = (string) ($defaults['css_framework'] ?? $ui['css_framework'] ?? 'custom');
            $defaults['icon_set'] = $fw === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        $container->prependExtensionConfig('nowo_ui_kit', $defaults);
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
        $container->setParameter(Configuration::ALIAS . '.ui.layout_template', $config['ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.ui.css_framework', $config['ui']['css_framework']);
        $container->setParameter(Configuration::ALIAS . '.ui.default_locale', $config['ui']['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.ui.locales', $config['ui']['locales']);
        $container->setParameter(
            Configuration::ALIAS . '.ui.locales_requirement',
            implode('|', $config['ui']['locales']),
        );
        $container->setParameter(Configuration::ALIAS . '.ui.request_timeout_seconds', $config['ui']['request_timeout_seconds']);
        $container->setParameter(Configuration::ALIAS . '.ui.required_roles', $config['ui']['required_roles']);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', $config['execution_url_allowlist']);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', $config['execution_url_allowlist_required']);
        $container->setParameter(Configuration::ALIAS . '.secrets.encrypt', $config['secrets']['encrypt']);
        $encryptionKey = $config['secrets']['encryption_key'];
        $container->setParameter(
            Configuration::ALIAS . '.secrets.encryption_key_material',
            is_string($encryptionKey) && $encryptionKey !== '' ? $encryptionKey : '%kernel.secret%',
        );
        $container->setParameter(Configuration::ALIAS . '.security', $config['security']);
        $container->setParameter(Configuration::ALIAS . '.security.access_roles', $config['security']['access_roles']);
        $container->setParameter(Configuration::ALIAS . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter(
            Configuration::ALIAS . '.security.effective_access_roles',
            $config['security']['access_roles'] !== [] ? $config['security']['access_roles'] : $config['ui']['required_roles'],
        );

        $container->register(ExecutionUrlValidator::class)
            ->setArgument('$allowlist', $config['execution_url_allowlist']);

        $this->registerAccessChecker($container, $config['security'], $config['ui']['required_roles']);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<string, mixed> $security
     * @param list<string> $legacyRequiredRoles
     */
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
    }
}
