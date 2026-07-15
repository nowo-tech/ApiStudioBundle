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
                        ->arrayNode('required_roles')
                            ->info('Required roles to access Api Studio UI (user must have at least one). Empty disables bundle-level checks.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->example(['ROLE_ADMIN', 'ROLE_API_STUDIO'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('execution_url_allowlist')
                    ->info('Optional allowlist for outbound request URLs (substring or regex with # prefix). Empty = any public URL after SSRF check.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->example(['api.example.com', '#^https://staging\\.example\\.com/#'])
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
                            ->info('Required roles for Api Studio UI. Empty disables bundle-level checks.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
