<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * In-memory SQLite entity manager with the Api Studio mapping, for repository and service tests.
 */
trait InMemoryOrmTrait
{
    private function createEntityManager(bool $createSchema = true): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
            sys_get_temp_dir() . '/nowo_api_studio_test_proxies',
        );
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        if ($createSchema) {
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        }

        return $em;
    }

    private function createRegistry(EntityManager $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $registry->method('getManager')->willReturn($em);

        return $registry;
    }
}
