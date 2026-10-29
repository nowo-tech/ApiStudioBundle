<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Service\SchemaSyncService;
use PHPUnit\Framework\TestCase;

use function dirname;
use function extension_loaded;
use function implode;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The sync SQL must create or alter API Studio tables only, never drop host tables (ORM 3 has no save mode).
 */
final class SchemaSyncServiceIntegrationTest extends TestCase
{
    public function testSyncSchemaSqlNeverTouchesHostTables(): void
    {
        $em         = $this->createEntityManager();
        $connection = $em->getConnection();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $connection->executeStatement('CREATE TABLE host_customer (id INTEGER PRIMARY KEY, name VARCHAR(50))');
        $service = new SchemaSyncService($em);

        self::assertSame([], $service->getUpdateSchemaSql());
        self::assertStringNotContainsStringIgnoringCase('host_customer', implode("\n", $service->getSyncSchemaSql()));
    }

    public function testUpdateSchemaSqlCreatesMissingBundleTables(): void
    {
        $em = $this->createEntityManager();
        $em->getConnection()->executeStatement('CREATE TABLE host_customer (id INTEGER PRIMARY KEY)');

        $sql = implode("\n", (new SchemaSyncService($em))->getUpdateSchemaSql());

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringNotContainsStringIgnoringCase('host_customer', $sql);
    }

    private function createEntityManager(): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
            sys_get_temp_dir() . '/nowo_api_studio_schema_proxies',
        );
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
