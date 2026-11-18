<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Entity managers with tenancy and workspace scopes on (1.17): the table prefix, the tenant and scope columns, slugs
 * unique per (tenant, scope), the SQL filter registered (disabled). SQLite in memory by default, or any connection
 * (the real-database suite).
 */
trait ScopeOrmTrait
{
    private function scopeConfiguration(): Configuration
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $config->addFilter(TenantSqlFilter::NAME, TenantSqlFilter::class);

        return $config;
    }

    private function scopeManager(Connection $connection, bool $tenancy = true): EntityManagerInterface
    {
        $config = $this->scopeConfiguration();
        $em     = new EntityManager($connection, $config);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', $tenancy, false, false, false, true));

        return $em;
    }

    private function sqliteScopeRegistry(bool $tenancy = true): ScopeManagerRegistry
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->scopeConfiguration());
        $registry   = new ScopeManagerRegistry($connection, fn (Connection $connection): EntityManagerInterface => $this->scopeManager($connection, $tenancy));
        $this->createScopeSchema($this->scopeEntityManager($registry));

        return $registry;
    }

    private function createScopeSchema(EntityManagerInterface $em): void
    {
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function scopeEntityManager(ScopeManagerRegistry $registry): EntityManagerInterface
    {
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
