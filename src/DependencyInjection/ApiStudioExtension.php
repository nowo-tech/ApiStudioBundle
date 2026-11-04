<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection;

use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Routing\ApiStudioRouteScopeListener;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ConfigurableApiStudioAccessChecker;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Security\LegacyPermissionChecker;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantListener;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function sprintf;
use function trigger_deprecation;

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
                    // Tenancy defence in depth: registered disabled, enabled per Api Studio request (TenantSqlFilterListener).
                    'filters' => [
                        TenantSqlFilter::NAME => ['class' => TenantSqlFilter::class, 'enabled' => false],
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the {@code api_studio} profile (labels domain + the semantic
     * {@code nowo-ui-input} class). Forms select it via {@code #[FormKitConfig]}. Does not change the host
     * {@code default_profile}; seeds FormKit {@code css_framework} only from the deprecated {@code ui.css_framework}.
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
            // Deprecated ui.css_framework (null = leave FormKit's own default).
            $fw = $this->legacyCssFramework($container);
            if ($fw !== null) {
                // FormKit accepts only bootstrap|tailwind|foundation|none (not bootstrap5 / custom / …).
                $seed['css_framework'] = match ($fw) {
                    'tailwind'   => 'tailwind',
                    'foundation' => 'foundation',
                    default      => 'bootstrap',
                };
            }
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'api_studio' => [
                    'alias'              => 'api_studio',
                    'translation_domain' => 'NowoApiStudioBundle',
                    // Auto help / placeholder keys only when the catalogue has them (labels are always keys).
                    'convention_requires_translation' => true,
                    // Semantic hooks only (REQ-UI-003): framework classes come from the host form theme.
                    'defaults' => [
                        'attr' => ['class' => 'nowo-ui-input'],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * When UiKit is installed and the deprecated ui.css_framework is set, seed nowo_ui_kit.css_framework / icon_set
     * from it (BC for 1.x hosts). Does not override keys the host already set under nowo_ui_kit.
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

        // Deprecated ui.css_framework: seeds the UiKit stack only when set (null = UiKit's own defaults).
        $fw = $this->legacyCssFramework($container);
        if ($fw === null || ($hostHasCssFramework && $hostHasIconSet)) {
            return;
        }

        $defaults = [];
        if (!$hostHasCssFramework) {
            $defaults['css_framework'] = $fw;
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = 'bootstrap-icons';
        }

        $container->prependExtensionConfig('nowo_ui_kit', $defaults);
    }

    /**
     * Security switches must be literal booleans: an `%env()%` placeholder is a non-empty string at compile time, so
     * it would be read as `true` whatever the variable says at runtime (fail open). `security.allow_unauthenticated`
     * is a dev / demo switch: refused unless `kernel.debug` is true and never in the `prod` environment.
     *
     * @param array<string, mixed> $config
     */
    private function assertSecurityFlags(array $config, ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $security */
        $security = $config['security'];
        $flags    = [
            'enabled'                          => $config['enabled'],
            'tenancy.enabled'                  => $config['tenancy']['enabled'],
            'security.allow_unauthenticated'   => $security['allow_unauthenticated'],
            'execution_url_allowlist_required' => $config['execution_url_allowlist_required'],
        ];
        foreach ($flags as $name => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.%s must be a literal boolean (true or false); environment variable placeholders are not supported for security switches.', $name));
            }
        }

        if ($security['allow_unauthenticated'] !== true) {
            return;
        }

        $debug       = $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true;
        $environment = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : null;
        if (!$debug || $environment === 'prod') {
            throw new InvalidConfigurationException('nowo_api_studio.security.allow_unauthenticated: true is for development and demos only: it requires kernel.debug and is refused in the prod environment. Configure security.access_roles (or an access_checker) instead.');
        }
    }

    /**
     * The deprecated `ui.css_framework` of the host configuration, null when not set.
     */
    private function legacyCssFramework(ContainerBuilder $container): ?string
    {
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $fw     = $config['ui']['css_framework'] ?? null;

        return is_string($fw) ? $fw : null;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertSecurityFlags($config, $container);

        $container->setParameter(Configuration::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(Configuration::ALIAS . '.environments', $config['environments']);
        $container->setParameter(Configuration::ALIAS . '.connection', $config['connection']);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.ui.path', $config['ui']['path']);
        $container->setParameter(Configuration::ALIAS . '.ui.layout_template', $config['ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.ui.css_framework', $config['ui']['css_framework']);
        if ($config['ui']['css_framework'] !== null) {
            trigger_deprecation('nowo-tech/api-studio-bundle', '1.10', 'The "nowo_api_studio.ui.css_framework" option is deprecated: set "nowo_ui_kit.css_framework" (UiKit page contract) instead.');
        }
        $container->setParameter(Configuration::ALIAS . '.ui.default_locale', $config['ui']['default_locale']);
        $container->setParameter(Configuration::ALIAS . '.ui.locale_scope', $config['ui']['locale_scope']);
        $container->setParameter(Configuration::ALIAS . '.ui.locales', $config['ui']['locales']);
        $container->setParameter(
            Configuration::ALIAS . '.ui.locales_requirement',
            implode('|', $config['ui']['locales']),
        );
        $container->setParameter(Configuration::ALIAS . '.ui.request_timeout_seconds', $config['ui']['request_timeout_seconds']);
        $container->setParameter(Configuration::ALIAS . '.ui.list_page_size', $config['ui']['list_page_size']);
        $container->setParameter(Configuration::ALIAS . '.ui.required_roles', $config['ui']['required_roles']);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist', $config['execution_url_allowlist']);
        $container->setParameter(Configuration::ALIAS . '.execution_url_allowlist_required', $config['execution_url_allowlist_required']);
        $container->setParameter(Configuration::ALIAS . '.execution_max_redirects', $config['execution_max_redirects']);
        $container->setParameter(Configuration::ALIAS . '.execution_max_response_bytes', $config['execution_max_response_bytes']);
        $container->setParameter(Configuration::ALIAS . '.secrets.encrypt', $config['secrets']['encrypt']);
        $container->setParameter(Configuration::ALIAS . '.scripts.mode', $config['scripts']['mode']);
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

        $this->registerTenancy($container, $config['tenancy']);
        $this->registerPermissions($container, $config['security'], $config['history']);
        $this->registerPathPlaceholders($container, $config['ui']['path'], $config['ui']['path_requirements']);

        $this->registerAccessChecker($container, $config['security'], $config['ui']['required_roles']);
    }

    /**
     * @param array{enabled: bool, resolver: ?string, sql_filter: bool} $tenancy
     */
    private function registerTenancy(ContainerBuilder $container, array $tenancy): void
    {
        $enabled = $tenancy['enabled'];
        $container->setParameter(Configuration::ALIAS . '.tenancy.enabled', $enabled);
        $resolver = $tenancy['resolver'];
        if ($enabled && (!is_string($resolver) || $resolver === '')) {
            throw new InvalidConfigurationException('nowo_api_studio.tenancy.enabled requires nowo_api_studio.tenancy.resolver (a service implementing ApiStudioTenantResolverInterface).');
        }

        $container->getDefinition(ApiStudioTenantContext::class)->setArgument('$enabled', $enabled);
        $container->getDefinition(TenantSqlFilterListener::class)->setArgument('$enabled', $enabled && $tenancy['sql_filter']);
        $container->getDefinition(ApiStudioTenantListener::class)
            ->setArgument('$enabled', $enabled)
            ->setArgument('$resolver', $enabled ? new Reference((string) $resolver) : null);
    }

    /**
     * @param array<string, mixed> $security
     * @param array{scope: string, actor_resolver: ?string} $history
     */
    private function registerPermissions(ContainerBuilder $container, array $security, array $history): void
    {
        $checker = $security['permission_checker'] ?? null;
        $container->setAlias(
            ApiStudioPermissionCheckerInterface::class,
            is_string($checker) && $checker !== '' ? $checker : LegacyPermissionChecker::class,
        );

        $container->setParameter(Configuration::ALIAS . '.history.scope', $history['scope']);
        $container->getDefinition(TablePrefixSubscriber::class)->setArgument('$historyActor', $history['scope'] === ApiStudioHistoryScope::SCOPE_USER);
        $container->getDefinition(ApiStudioHistoryScope::class)
            ->setArgument('$scope', $history['scope'])
            ->setArgument('$actorResolver', is_string($history['actor_resolver']) && $history['actor_resolver'] !== '' ? new Reference($history['actor_resolver']) : null);
    }

    /**
     * `ui.path` placeholders ({organization}) must not reuse a route parameter of the bundle, and every
     * `ui.path_requirements` entry must name one of them.
     *
     * @param array<string, string> $requirements
     */
    private function registerPathPlaceholders(ContainerBuilder $container, string $path, array $requirements): void
    {
        $placeholders = ApiStudioRouteScopeListener::placeholdersOf($path);
        foreach ($placeholders as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || in_array($name, ApiStudioRouteScopeListener::RESERVED_PARAMETERS, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.ui.path: the placeholder "{%s}" is not allowed (lowercase letters, digits and underscores; not one of %s).', $name, implode(', ', ApiStudioRouteScopeListener::RESERVED_PARAMETERS)));
            }
        }
        foreach (array_keys($requirements) as $name) {
            if (!in_array($name, $placeholders, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.ui.path_requirements: "%s" is not a placeholder of ui.path.', $name));
            }
        }

        $container->setParameter(Configuration::ALIAS . '.ui.path_placeholders', $placeholders);
        $container->setParameter(Configuration::ALIAS . '.ui.path_requirements', $requirements);
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
