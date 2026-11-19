<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit;

use Nowo\ApiStudioBundle\Bridge\SearchKit\ApiStudioSearchDefinition;
use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Search\SearchAccess;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchHitMapper;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * `nowo_api_studio.search`: opt-in, every group by default; groups and the whole search can be switched off; the
 * switch is a literal boolean; the tenant resolver, scope labels and scoped mount are the studio's.
 */
final class SearchConfigTest extends TestCase
{
    use SkipsWithoutSearchKit;

    protected function setUp(): void
    {
        $this->skipWithoutSearchKit();
    }

    public function testOptInWithEveryGroup(): void
    {
        self::assertFalse($this->load([])->hasDefinition('nowo_api_studio.search_kit.workspaces'));
        self::assertFalse($this->load([])->getParameter('nowo_api_studio.search_kit.enabled'));

        $container = $this->load([['search_kit' => ['enabled' => true]]]);
        self::assertTrue($container->getParameter('nowo_api_studio.search_kit.enabled'));
        self::assertSame(['workspaces', 'services', 'endpoints'], $container->getParameter('nowo_api_studio.search_kit.groups'));
        foreach (['workspaces', 'services', 'endpoints'] as $group) {
            $definition = $container->getDefinition('nowo_api_studio.search_kit.' . $group);
            self::assertSame(ApiStudioSearchDefinition::class, $definition->getClass());
            self::assertTrue($definition->hasTag(EntitySearchDefinition::TAG));
        }
        self::assertTrue($container->hasDefinition(SearchAccess::class));
        self::assertNull($container->getDefinition(SearchContextResolver::class)->getArgument('$tenantResolver'));
        self::assertNull($container->getDefinition(SearchHitMapper::class)->getArgument('$scopeLabels'));
        self::assertFalse($container->getDefinition(SearchHitMapper::class)->getArgument('$scopedRoutes'));
    }

    public function testGroupsAndTheWholeSearchCanBeSwitchedOff(): void
    {
        $container = $this->load([['search_kit' => ['enabled' => true, 'groups' => ['services' => false]]]]);
        self::assertSame(['workspaces', 'endpoints'], $container->getParameter('nowo_api_studio.search_kit.groups'));
        self::assertFalse($container->hasDefinition('nowo_api_studio.search_kit.services'));

        $off = $this->load([['search_kit' => ['enabled' => false]]]);
        self::assertFalse($off->getParameter('nowo_api_studio.search_kit.enabled'));
        self::assertFalse($off->hasDefinition(SearchContextResolver::class));

        self::assertFalse($this->load([['enabled' => false, 'search_kit' => ['enabled' => true]]])->hasDefinition(SearchContextResolver::class), 'bundle disabled');
    }

    public function testTheSwitchIsALiteralBoolean(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('search_kit.enabled');
        $this->load([['search_kit' => ['enabled' => '%env(bool:SEARCH)%']]]);
    }

    public function testStudioWiringIsReused(): void
    {
        $container = $this->load([[
            'search_kit' => ['enabled' => true],
            'tenancy'    => ['enabled' => true, 'resolver' => 'app.tenant_resolver'],
            'scope'      => ['enabled' => true, 'resolver' => 'app.scope_resolver', 'access_checker' => 'app.scope_checker', 'label_provider' => 'app.scope_labels', 'path' => '/p/{project}/studio'],
        ]]);
        self::assertEquals(new Reference('app.tenant_resolver'), $container->getDefinition(SearchContextResolver::class)->getArgument('$tenantResolver'));
        self::assertEquals(new Reference('app.scope_labels'), $container->getDefinition(SearchHitMapper::class)->getArgument('$scopeLabels'));
        self::assertTrue($container->getDefinition(SearchHitMapper::class)->getArgument('$scopedRoutes'));
        self::assertTrue($container->getDefinition('nowo_api_studio.search_kit.workspaces')->getArgument('$tenantListAll'));

        $unscoped = $this->load([[
            'search_kit' => ['enabled' => true],
            'scope'      => ['enabled' => true, 'resolver' => 'app.scope_resolver', 'access_checker' => 'app.scope_checker', 'tenant_list' => 'unscoped'],
        ]]);
        self::assertFalse($unscoped->getDefinition('nowo_api_studio.search_kit.endpoints')->getArgument('$tenantListAll'));
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.environment', 'test');
        (new ApiStudioExtension())->load($configs, $container);

        return $container;
    }
}
