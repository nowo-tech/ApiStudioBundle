<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\EventListener\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ApiStudioEntityManagerPassTest extends TestCase
{
    public function testWithoutTheOptionNothingChanges(): void
    {
        $container = $this->container(null);
        $before    = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();

        (new ApiStudioEntityManagerPass())->process($container);
        (new ApiStudioEntityManagerPass())->process(new ContainerBuilder());

        self::assertSame($before, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
        self::assertFalse($container->getDefinition('doctrine.orm.api_studio_configuration')->hasMethodCall('addFilter'));
    }

    public function testMapsTheEntitiesAndTheFilterOnTheConfiguredManagerOnly(): void
    {
        $container = $this->container('api_studio');

        (new ApiStudioEntityManagerPass())->process($container);

        $default = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
        self::assertCount(1, $default, 'the auto-mapped bundle driver is removed from the default manager');
        self::assertSame('App\\Entity', $default[0][1][1]);

        $studio = $container->getDefinition('doctrine.orm.api_studio_metadata_driver')->getMethodCalls();
        self::assertCount(1, $studio);
        self::assertSame(ApiStudioEntityManagerPass::ENTITY_NAMESPACE, $studio[0][1][1]);
        $driver = $studio[0][1][0];
        self::assertInstanceOf(Definition::class, $driver);
        self::assertSame(AttributeDriver::class, $driver->getClass());

        self::assertSame([['addFilter', [TenantSqlFilter::NAME, TenantSqlFilter::class]]], $container->getDefinition('doctrine.orm.api_studio_configuration')->getMethodCalls());
        self::assertFalse($container->getDefinition('doctrine.orm.default_configuration')->hasMethodCall('addFilter'));

        // Idempotent: a second run neither duplicates the driver nor the filter.
        (new ApiStudioEntityManagerPass())->process($container);
        self::assertCount(1, $container->getDefinition('doctrine.orm.api_studio_metadata_driver')->getMethodCalls());
        self::assertCount(1, $container->getDefinition('doctrine.orm.api_studio_configuration')->getMethodCalls());
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_api_studio.entity_manager is "reports", but no such Doctrine entity manager is configured (known: "default", "api_studio")');

        (new ApiStudioEntityManagerPass())->process($this->container('reports'));
    }

    public function testManagerWithoutConfigurationDefinitionStillGetsTheMapping(): void
    {
        $container = $this->container('api_studio');
        $container->removeDefinition('doctrine.orm.api_studio_configuration');
        $container->removeDefinition('doctrine.orm.default_metadata_driver');

        (new ApiStudioEntityManagerPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.api_studio_metadata_driver')->getMethodCalls());
    }

    public function testTheBoundarySubscriberGetsTheBundleOwnedManagerOnly(): void
    {
        $container = $this->container('api_studio');
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->register(EntityManagerBoundarySubscriber::class)->setArgument('$managers', new IteratorArgument([]));

        (new ApiStudioEntityManagerPass())->process($container);

        $managers = $container->getDefinition(EntityManagerBoundarySubscriber::class)->getArgument('$managers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        $values = $managers->getValues();
        self::assertCount(1, $values);
        self::assertInstanceOf(Reference::class, $values[0]);
        self::assertSame('doctrine.orm.api_studio_entity_manager', (string) $values[0]);
        self::assertSame(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE, $values[0]->getInvalidBehavior(), 'a boundary never builds the manager');
    }

    public function testTheBoundarySubscriberNeverGetsTheHostDefaultManager(): void
    {
        $container = $this->container('default');
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->register(EntityManagerBoundarySubscriber::class)->setArgument('$managers', new IteratorArgument([]));

        (new ApiStudioEntityManagerPass())->process($container);

        $managers = $container->getDefinition(EntityManagerBoundarySubscriber::class)->getArgument('$managers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        self::assertSame([], $managers->getValues(), 'entity_manager naming the host default manager: the host clears it');
    }

    private function container(?string $managerName): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(ApiStudioExtension::ENTITY_MANAGER_PARAMETER, $managerName);
        $container->setParameter('doctrine.entity_managers', [
            'default'    => 'doctrine.orm.default_entity_manager',
            'api_studio' => 'doctrine.orm.api_studio_entity_manager',
        ]);
        $container->register('doctrine.orm.default_metadata_driver')
            ->addMethodCall('addDriver', [new Reference('doctrine.orm.default_attribute_metadata_driver'), 'App\\Entity'])
            ->addMethodCall('addDriver', [new Reference('doctrine.orm.default_attribute_metadata_driver'), ApiStudioEntityManagerPass::ENTITY_NAMESPACE]);
        $container->register('doctrine.orm.api_studio_metadata_driver');
        $container->register('doctrine.orm.default_configuration');
        $container->register('doctrine.orm.api_studio_configuration');

        return $container;
    }
}
