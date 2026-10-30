<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;

/**
 * Configuration tree for nowo_api_studio.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_api_studio';

    public const CSS_FRAMEWORKS = ['bootstrap5', 'tailwind', 'foundation', 'custom'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->arrayNode('environments')
                    ->info('Environments created for each workspace created from the UI (first = default). Empty list disables seeding.')
                    ->prototype('scalar')->end()
                    ->defaultValue(['dev', 'test', 'prod'])
                ->end()
                ->scalarNode('connection')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('api_studio_')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || !preg_match('/^[a-z0-9_]+$/', $value))
                        ->thenInvalid('The table_prefix must contain only lowercase letters, numbers, and underscores.')
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('/api-studio')
                            ->cannotBeEmpty()
                            ->info('Dashboard path prefix (host should lock with security.access_control).')
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout the Api Studio pages (UiKit page scaffolds) extend. Null (default) = nowo_ui_kit.layouts.admin, set once for every UiKit bundle. A layout that renders the UiKit canonical blocks is used as is; a pre-1.10 layout that renders {% block body %} is wrapped in a deprecated bridge.')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultNull()
                            ->info('Deprecated since 1.10: the CSS stack is nowo_ui_kit.css_framework (UiKit page contract). When set (and nowo_ui_kit.css_framework is not), it still seeds nowo_ui_kit.css_framework / icon_set and the FormKit css_framework.')
                        ->end()
                        ->scalarNode('default_locale')
                            ->defaultValue('en')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('locales')
                            ->scalarPrototype()->end()
                            ->defaultValue(['en', 'es', 'fr', 'it'])
                            ->requiresAtLeastOneElement()
                        ->end()
                        ->integerNode('request_timeout_seconds')
                            ->defaultValue(30)
                            ->min(1)
                            ->max(300)
                        ->end()
                        ->integerNode('list_page_size')
                            ->info('Rows per page on the workspace list and the dashboard (REQ-PERF-001).')
                            ->defaultValue(50)
                            ->min(1)
                            ->max(500)
                        ->end()
                        ->arrayNode('required_roles')
                            ->info('Legacy role list used when security.access_roles is empty. An empty effective list denies; both empty fails compilation unless access_checker or allow_unauthenticated is set.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->example(['ROLE_ADMIN', 'ROLE_API_STUDIO'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('execution_url_allowlist')
                    ->info('Optional allowlist for outbound request URLs: exact host (api.example.com), host suffix (.example.com), URL with scheme/port/path prefix, or regex with # prefix. Empty = any public URL after SSRF check unless execution_url_allowlist_required is true.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->example(['api.example.com', '.example.org', '#^https://staging\\.example\\.com/#'])
                ->end()
                ->integerNode('execution_max_redirects')
                    ->defaultValue(0)
                    ->min(0)
                    ->info('Redirects followed when executing requests. 0 (default) returns 3xx responses as-is. Followed hops are blocked from private networks but are not re-checked against execution_url_allowlist.')
                ->end()
                ->integerNode('execution_max_response_bytes')
                    ->defaultValue(10_485_760)
                    ->min(1024)
                    ->info('Largest response body (bytes) read from a remote API, SOAP endpoint or WSDL document (default 10 MiB); a bigger transfer is stopped and reported as an error.')
                ->end()
                ->booleanNode('execution_url_allowlist_required')
                    ->defaultFalse()
                    ->info('When true, an empty execution_url_allowlist fails container compilation. Recommended true in production.')
                ->end()
                ->arrayNode('secrets')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('encrypt')
                            ->defaultTrue()
                            ->info('Encrypt environment variables marked secret at rest (sodium). Existing plaintext values remain readable and are re-encrypted on next save.')
                        ->end()
                        ->scalarNode('encryption_key')
                            ->defaultNull()
                            ->info('Optional key material for secret encryption. Defaults to kernel.secret when null.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Required roles for Api Studio UI (user needs at least one). Empty falls back to ui.required_roles; an empty effective list denies, and compilation fails unless access_checker or allow_unauthenticated is set.')
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                            ->info('DEV/DEMO ONLY. When true, the UI may load without SecurityBundle / without login. Production MUST keep false.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
