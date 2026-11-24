<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection;

use Nowo\ApiStudioBundle\Bridge\SearchKit\ApiStudioSearchDefinition;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Routing\ApiStudioRouteScopeListener;
use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\ScopeListener;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Script\Runner\ApiStudioScriptRunnerInterface;
use Nowo\ApiStudioBundle\Script\Runner\NullScriptRunner;
use Nowo\ApiStudioBundle\Script\Runner\ProcessScriptRunner;
use Nowo\ApiStudioBundle\Search\SearchAccess;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchHitMapper;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Security\ApiWorkspaceAccess;
use Nowo\ApiStudioBundle\Security\ConfigurableApiStudioAccessChecker;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Security\LegacyPermissionChecker;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeeder;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantListener;
use Nowo\ApiStudioBundle\Tenancy\TenantProviderResolver;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function dirname;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trigger_deprecation;

/**
 * Loads bundle configuration and registers services.
 */
final class ApiStudioExtension extends Extension implements PrependExtensionInterface
{
    /** Container parameter holding `entity_manager` (null: the manager that maps the entities). */
    public const ENTITY_MANAGER_PARAMETER = Configuration::ALIAS . '.entity_manager';

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

        // 1.12: with a dedicated entity_manager the mapping and the tenant filter go on that manager only
        // (ApiStudioEntityManagerPass). Nothing is prepended into the ORM configuration then: a prepended top-level
        // `orm` key would create (or become) an entity manager named "default" in a multi-manager host.
        if ($container->hasExtension('doctrine') && $this->configuredEntityManager($container) === null) {
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
            'scope.enabled'                    => $config['scope']['enabled'],
            'search_kit.enabled'               => $config['search_kit']['enabled'],
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
     * `entity_manager` of the host configuration (null: the manager that maps the entities).
     */
    private function configuredEntityManager(ContainerBuilder $container): ?string
    {
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $name   = $config['entity_manager'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
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
        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, is_string($config['entity_manager']) && $config['entity_manager'] !== '' ? $config['entity_manager'] : null);
        $container->setParameter(Configuration::ALIAS . '.table_prefix', $config['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.ui.path', $config['ui']['path']);
        $container->setParameter(Configuration::ALIAS . '.ui.layout_template', $config['ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.ui.header_actions', $config['ui']['header_actions']);
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
        $container->setParameter(Configuration::ALIAS . '.scripts.confirm_stored', $config['scripts']['confirm_stored']);
        $container->setParameter(Configuration::ALIAS . '.scripts.limits.time_ms', $config['scripts']['limits']['time_ms']);
        $container->setParameter(Configuration::ALIAS . '.scripts.limits.memory_mb', $config['scripts']['limits']['memory_mb']);
        $container->setParameter(Configuration::ALIAS . '.scripts.limits.output_kb', $config['scripts']['limits']['output_kb']);
        $container->setParameter(Configuration::ALIAS . '.scripts.limits.send_requests', $config['scripts']['limits']['send_requests']);
        $this->registerServerRunner($container, $config['scripts']['server_runner']);
        // 1.15: workspace / folder scripts only exist in sandbox mode (mapped columns, forms, console chain).
        $container->setParameter(Configuration::ALIAS . '.scripts.levels', $config['scripts']['mode'] === 'sandbox');
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

        $this->registerTenancy($container, $config['tenancy'], $config['scope']['enabled']);
        $this->registerScope($container, $config['scope'], $config['ui']['path'], $config['tenancy']['enabled'], is_string($config['secrets']['source'] ?? null) && $config['secrets']['source'] !== '');
        $this->registerConcurrency($container, $config['concurrency']);
        $this->registerPermissions($container, $config['security'], $config['history']);
        $this->registerFolders($container, $config['folders'], $config['security']);
        $source = $config['secrets']['source'] ?? null;
        $container->getDefinition(HostSecretResolver::class)
            ->setArgument('$source', is_string($source) && $source !== '' ? new Reference($source) : null);
        $this->registerPathPlaceholders($container, $config['ui']['path'], $config['ui']['path_requirements']);

        $this->registerAccessChecker($container, $config['security'], $config['ui']['required_roles']);
        $this->registerAccessPolicy($container, $config['security']);
        $this->registerSearch($container, $config['search_kit'], $config['enabled']);
    }

    /**
     * 1.18: the SearchKit groups (workspaces, services, endpoints), registered only when SearchKit (^1.9) is installed
     * and `search_kit.enabled`: one {@see ApiStudioSearchDefinition} per enabled group, tagged for SearchKit's
     * EntitySearchProvider. The tenant resolver, the scope labels and the scoped mount are those of the studio routes.
     *
     * @param array{enabled: mixed, groups: array<string, bool>} $search
     */
    private function registerSearch(ContainerBuilder $container, array $search, bool $bundleEnabled): void
    {
        $enabled = $bundleEnabled && $search['enabled'] === true && class_exists(EntitySearchDefinition::class);
        $groups  = [];
        foreach (SearchKind::cases() as $kind) {
            if ($search['groups'][$kind->value] ?? false) {
                $groups[] = $kind->value;
            }
        }
        $container->setParameter(Configuration::ALIAS . '.search_kit.enabled', $enabled);
        $container->setParameter(Configuration::ALIAS . '.search_kit.groups', $groups);
        if (!$enabled) {
            return;
        }

        $nullable = ContainerInterface::NULL_ON_INVALID_REFERENCE;
        $container->register(SearchContextResolver::class, SearchContextResolver::class)
            ->setArgument('$requestStack', new Reference('request_stack', $nullable))
            ->setArgument('$tenantContext', new Reference(ApiStudioTenantContext::class))
            ->setArgument('$tenantResolver', $container->getDefinition(ApiStudioTenantListener::class)->getArguments()['$resolver'] ?? null)
            ->setArgument('$scopeContext', new Reference(ScopeContext::class))
            ->setArgument('$dispatcher', new Reference('event_dispatcher', $nullable));
        $container->register(SearchAccess::class, SearchAccess::class)
            ->setArgument('$authorization', new Reference(ApiStudioAuthorization::class))
            ->setArgument('$workspaces', new Reference(ApiWorkspaceRepository::class))
            ->setArgument('$folders', new Reference(ApiFolderRepository::class))
            ->setArgument('$folderAccess', new Reference(FolderAccess::class));
        $container->register(SearchHitMapper::class, SearchHitMapper::class)
            ->setArgument('$router', new Reference('router'))
            ->setArgument('$scopeLabels', $container->getDefinition(ApiStudioPageFactory::class)->getArguments()['$scopeLabels'] ?? null)
            ->setArgument('$scopedRoutes', $container->getDefinition(ApiStudioUrlGenerator::class)->getArguments()['$scopedRoutes'] ?? false);
        foreach ($groups as $group) {
            $container->register('nowo_api_studio.search_kit.' . $group, ApiStudioSearchDefinition::class)
                ->setArgument('$kind', SearchKind::from($group))
                ->setArgument('$contexts', new Reference(SearchContextResolver::class))
                ->setArgument('$authorization', new Reference(ApiStudioAuthorization::class))
                // Registered by ApiStudioSecurityPass; missing = every search is refused (deny by default).
                ->setArgument('$entry', new Reference(ApiStudioAccessSubscriber::class, $nullable))
                ->setArgument('$workspaces', new Reference(ApiWorkspaceRepository::class))
                ->setArgument('$services', new Reference(ApiServiceRepository::class))
                ->setArgument('$endpoints', new Reference(ApiEndpointRepository::class))
                ->setArgument('$access', new Reference(SearchAccess::class))
                ->setArgument('$mapper', new Reference(SearchHitMapper::class))
                ->setArgument('$translator', new Reference('translator', $nullable))
                ->setArgument('$tenantListAll', $container->getParameter(Configuration::ALIAS . '.scope.tenant_list') === 'all')
                ->addTag(EntitySearchDefinition::TAG);
        }
    }

    /**
     * 1.16: the route list (bundle + `security.host_routes`), the 403 / 404 policy and the feature gate.
     *
     * @param array<string, mixed> $security
     */
    private function registerAccessPolicy(ContainerBuilder $container, array $security): void
    {
        $status = $security['access_denied_response'];
        if (!in_array($status, ApiStudioAccessPolicy::RESPONSES, true)) {
            throw new InvalidConfigurationException('nowo_api_studio.security.access_denied_response must be the literal 403 or 404; environment variable placeholders are not supported for security switches.');
        }

        /** @var array<string, mixed> $hostRoutes */
        $hostRoutes = $security['host_routes'];
        foreach ($hostRoutes as $route => $action) {
            $known = is_string($action) ? ApiStudioAction::tryFrom($action) : null;
            if (str_starts_with((string) $route, ApiStudioRoutes::BUNDLE_PREFIX) || !in_array($known, ApiStudioRoutes::HOST_ROUTE_ACTIONS, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.security.host_routes."%s": use a route of the host (not "%s*") mapped to studio.open or workspace.create; workspace actions are checked in the host controller with ApiStudioAuthorization::requireWorkspace().', $route, ApiStudioRoutes::BUNDLE_PREFIX));
            }
        }

        $container->setParameter(Configuration::ALIAS . '.security.access_denied_response', $status);
        $container->getDefinition(ApiStudioRoutes::class)->setArgument('$hostRoutes', $hostRoutes);
        $container->getDefinition(ApiStudioAccessPolicy::class)
            ->setArgument('$deniedStatus', $status)
            ->setArgument('$tokenStorage', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $gate = $security['feature_gate'] ?? null;
        $container->setParameter(Configuration::ALIAS . '.security.feature_gate', is_string($gate) && $gate !== '' ? $gate : null);
    }

    /**
     * @param array{enabled: bool, resolver: ?string, provider: ?string, sql_filter: bool} $tenancy
     */
    private function registerTenancy(ContainerBuilder $container, array $tenancy, bool $scopes = false): void
    {
        $enabled = $tenancy['enabled'];
        $container->setParameter(Configuration::ALIAS . '.tenancy.enabled', $enabled);
        $resolver = is_string($tenancy['resolver']) && $tenancy['resolver'] !== '' ? $tenancy['resolver'] : null;
        $provider = is_string($tenancy['provider']) && $tenancy['provider'] !== '' ? $tenancy['provider'] : null;
        if ($resolver !== null && $provider !== null) {
            throw new InvalidConfigurationException('nowo_api_studio.tenancy: set either resolver (ApiStudioTenantResolverInterface) or provider (ApiStudioTenantProviderInterface), not both.');
        }
        if ($enabled && $resolver === null && $provider === null) {
            throw new InvalidConfigurationException('nowo_api_studio.tenancy.enabled requires nowo_api_studio.tenancy.resolver (a service implementing ApiStudioTenantResolverInterface) or nowo_api_studio.tenancy.provider (ApiStudioTenantProviderInterface).');
        }

        $resolverReference = null;
        if ($enabled && $resolver !== null) {
            $resolverReference = new Reference($resolver);
        } elseif ($enabled && $provider !== null) {
            // Request-free port (1.12): the adapter ignores the request and asks the host's provider.
            $container->register('nowo_api_studio.tenancy.provider_resolver', TenantProviderResolver::class)
                ->setArgument('$provider', new Reference($provider));
            $resolverReference = new Reference('nowo_api_studio.tenancy.provider_resolver');
        }

        $container->getDefinition(ApiStudioTenantContext::class)->setArgument('$enabled', $enabled);
        // 1.17: the filter also narrows by scope, so it runs with scopes on even without tenancy.
        $container->getDefinition(TenantSqlFilterListener::class)->setArgument('$enabled', ($enabled || $scopes) && $tenancy['sql_filter']);
        $container->getDefinition(ApiStudioTenantListener::class)
            ->setArgument('$enabled', $enabled)
            ->setArgument('$resolver', $resolverReference);
    }

    /**
     * 1.17 workspace scopes: the column, the resolver, the access checker (mandatory: deny by default), the scoped mount
     * and its layout, the seeder and the mover.
     *
     * @param array{enabled: bool, resolver: ?string, access_checker: ?string, label_provider: ?string, path: ?string, path_requirements: array<string, string>, layout_template: ?string, tenant_list: string} $scope
     */
    private function registerScope(ContainerBuilder $container, array $scope, string $uiPath, bool $tenancy, bool $secretSource): void
    {
        $enabled  = $scope['enabled'];
        $resolver = is_string($scope['resolver']) && $scope['resolver'] !== '' ? $scope['resolver'] : null;
        $checker  = is_string($scope['access_checker']) && $scope['access_checker'] !== '' ? $scope['access_checker'] : null;
        $labels   = is_string($scope['label_provider']) && $scope['label_provider'] !== '' ? $scope['label_provider'] : null;
        $path     = is_string($scope['path']) && $scope['path'] !== '' ? $scope['path'] : null;
        $layout   = is_string($scope['layout_template']) && $scope['layout_template'] !== '' ? $scope['layout_template'] : null;

        if ($enabled && ($resolver === null || $checker === null)) {
            throw new InvalidConfigurationException('nowo_api_studio.scope.enabled requires nowo_api_studio.scope.resolver (ScopeResolverInterface) and nowo_api_studio.scope.access_checker (ScopeAccessCheckerInterface): scoped access is denied by default.');
        }
        if (!$enabled && ($resolver !== null || $checker !== null || $labels !== null || $path !== null || $layout !== null || $scope['path_requirements'] !== [])) {
            throw new InvalidConfigurationException('nowo_api_studio.scope: resolver, access_checker, label_provider, path, path_requirements and layout_template need scope.enabled: true.');
        }

        $placeholders = [];
        if ($path !== null) {
            if (!str_starts_with($path, '/') || rtrim($path, '/') === rtrim($uiPath, '/')) {
                throw new InvalidConfigurationException('nowo_api_studio.scope.path must start with "/" and differ from nowo_api_studio.ui.path.');
            }
            $placeholders = $this->assertPlaceholders('scope.path', $path, $scope['path_requirements']);
        }

        $container->setParameter(Configuration::ALIAS . '.scope.enabled', $enabled);
        $container->setParameter(Configuration::ALIAS . '.scope.path', $path);
        $container->setParameter(Configuration::ALIAS . '.scope.path_placeholders', $placeholders);
        $container->setParameter(Configuration::ALIAS . '.scope.path_requirements', $scope['path_requirements']);
        $container->setParameter(Configuration::ALIAS . '.scope.layout_template', $layout);
        $container->setParameter(Configuration::ALIAS . '.scope.tenant_list', $scope['tenant_list']);

        $container->getDefinition(TablePrefixSubscriber::class)->setArgument('$scopes', $enabled);
        $container->getDefinition(ScopeContext::class)->setArgument('$enabled', $enabled);
        $container->getDefinition(ScopeListener::class)
            ->setArgument('$enabled', $enabled)
            ->setArgument('$resolver', $enabled ? new Reference((string) $resolver) : null);
        $container->getDefinition(ApiStudioAuthorization::class)
            ->setArgument('$scopeChecker', $enabled ? new Reference((string) $checker) : null)
            ->setArgument('$tenantListAll', $scope['tenant_list'] === 'all');
        $container->getDefinition(ApiWorkspaceAccess::class)->setArgument('$scopeChecker', $enabled ? new Reference((string) $checker) : null);
        $container->getDefinition(ApiStudioPageFactory::class)->setArgument('$scopeLabels', $labels !== null ? new Reference($labels) : null);
        $container->getDefinition(ApiStudioUrlGenerator::class)->setArgument('$scopedRoutes', $path !== null);
        $container->getDefinition(WorkspaceSeeder::class)
            ->setArgument('$scopes', $enabled)
            ->setArgument('$tenancy', $tenancy)
            ->setArgument('$secretSource', $secretSource);
        $container->getDefinition(WorkspaceScopeMover::class)->setArgument('$scopes', $enabled);
    }

    /**
     * 1.17: the 409 / 503 answer of lock conflicts on Api Studio routes.
     *
     * @param array{conflict_status: int, retry_after: int} $concurrency
     */
    private function registerConcurrency(ContainerBuilder $container, array $concurrency): void
    {
        $container->register('nowo_api_studio.conflict_response_policy', ConflictResponsePolicy::class)
            ->setArguments([$concurrency['conflict_status'], $concurrency['retry_after']]);
        $container->getDefinition(ConcurrencyConflictSubscriber::class)
            ->setArgument('$policy', new Reference('nowo_api_studio.conflict_response_policy'));
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
     * 1.15: the server-side script runner port. Enabled: the bundled {@see ProcessScriptRunner} (a host may alias the
     * interface to its own implementation instead); disabled: {@see NullScriptRunner} (scripts reported as not run).
     *
     * @param array{enabled: bool, command: list<string>} $runner
     */
    private function registerServerRunner(ContainerBuilder $container, array $runner): void
    {
        $container->setParameter(Configuration::ALIAS . '.scripts.server_runner.enabled', $runner['enabled']);
        if (!$runner['enabled']) {
            $container->setAlias(ApiStudioScriptRunnerInterface::class, NullScriptRunner::class)->setPublic(true);

            return;
        }
        $container->getDefinition(ProcessScriptRunner::class)
            ->setArgument('$command', $runner['command'])
            ->setArgument('$runnerPath', dirname(__DIR__) . '/Resources/runner/api-script-runner.js');
        $container->setAlias(ApiStudioScriptRunnerInterface::class, ProcessScriptRunner::class)->setPublic(true);
    }

    /**
     * @param array{enabled: bool, max_depth: int} $folders
     * @param array<string, mixed> $security
     */
    private function registerFolders(ContainerBuilder $container, array $folders, array $security): void
    {
        $container->setParameter(Configuration::ALIAS . '.folders.enabled', $folders['enabled']);
        $container->setParameter(Configuration::ALIAS . '.folders.max_depth', $folders['max_depth']);
        $container->getDefinition(TablePrefixSubscriber::class)->setArgument('$folders', $folders['enabled']);
        $container->getDefinition(TablePrefixSubscriber::class)->setArgument('$scriptLevels', '%' . Configuration::ALIAS . '.scripts.levels%');

        $checker = $security['folder_permission_checker'] ?? null;
        $container->getDefinition(FolderAccess::class)->setArgument(
            '$checker',
            $folders['enabled'] && is_string($checker) && $checker !== '' ? new Reference($checker) : null,
        );
    }

    /**
     * `ui.path` placeholders ({organization}) must not reuse a route parameter of the bundle, and every
     * `ui.path_requirements` entry must name one of them.
     *
     * @param array<string, string> $requirements
     */
    private function registerPathPlaceholders(ContainerBuilder $container, string $path, array $requirements): void
    {
        $placeholders = $this->assertPlaceholders('ui.path', $path, $requirements);

        $container->setParameter(Configuration::ALIAS . '.ui.path_placeholders', $placeholders);
        $container->setParameter(Configuration::ALIAS . '.ui.path_requirements', $requirements);
    }

    /**
     * Placeholders of a mount path (`ui.path`, 1.17 `scope.path`): lowercase names that do not reuse a route parameter
     * of the bundle; every requirement must name one of them.
     *
     * @param array<string, string> $requirements
     *
     * @return list<string>
     */
    private function assertPlaceholders(string $option, string $path, array $requirements): array
    {
        $placeholders = ApiStudioRouteScopeListener::placeholdersOf($path);
        foreach ($placeholders as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || in_array($name, ApiStudioRouteScopeListener::RESERVED_PARAMETERS, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.%s: the placeholder "{%s}" is not allowed (lowercase letters, digits and underscores; not one of %s).', $option, $name, implode(', ', ApiStudioRouteScopeListener::RESERVED_PARAMETERS)));
            }
        }
        foreach (array_keys($requirements) as $name) {
            if (!in_array($name, $placeholders, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_api_studio.%s_requirements: "%s" is not a placeholder of %s.', $option, $name, $option));
            }
        }

        return $placeholders;
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
