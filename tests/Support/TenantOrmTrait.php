<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;
use function ucfirst;

use const PHP_VERSION_ID;

/**
 * In-memory SQLite entity manager with tenancy on: the table prefix, the tenant columns, per-tenant slugs and the
 * tenant SQL filter registered (disabled).
 */
trait TenantOrmTrait
{
    private function createTenantEntityManager(): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $config->addFilter(TenantSqlFilter::NAME, TenantSqlFilter::class);

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', true, true));
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }

    private function tenantRegistry(EntityManager $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return $registry;
    }

    private function tenantWorkspace(EntityManager $em, string $tenantId, string $slug): ApiWorkspace
    {
        $workspace = (new ApiWorkspace(ucfirst($slug), $slug))->assignTenant($tenantId === '' ? ApiStudioTenant::instance() : new ApiStudioTenant('org', $tenantId));
        $em->persist($workspace);
        $em->flush();

        return $workspace;
    }
}
