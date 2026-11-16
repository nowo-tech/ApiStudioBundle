<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function extension_loaded;
use function implode;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.14 folders: mapped only with `folders.enabled` (1.13 schema otherwise), `created_by` with a binary NO PAD
 * collation on MySQL / MariaDB.
 */
final class FolderMappingTest extends TestCase
{
    public function testFoldersOffKeepThe113Schema(): void
    {
        $sql = implode("\n", $this->createSql(false));

        self::assertStringNotContainsString('api_studio_folder', $sql);
        self::assertStringNotContainsString('folder_id', $sql);

        $em = $this->entityManager(false, ['driver' => 'pdo_sqlite', 'memory' => true]);
        self::assertTrue($em->getClassMetadata(ApiFolder::class)->isMappedSuperclass);
        self::assertFalse($em->getClassMetadata(ApiEndpoint::class)->hasAssociation('folder'));
    }

    public function testFoldersOnMapTheTableAndTheEndpointColumn(): void
    {
        $sql = implode("\n", $this->createSql(true));

        self::assertStringContainsString('CREATE TABLE api_studio_folder', $sql);
        self::assertStringContainsString('folder_id', $sql);
        self::assertStringContainsString('api_studio_idx_endpoint_folder', $sql);
        self::assertStringContainsString('api_studio_idx_folder_service_parent', $sql);
    }

    public function testMysqlCreatorColumnIsBinaryNoPad(): void
    {
        $em  = $this->entityManager(true, ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0', 'host' => 'localhost.invalid']);
        $sql = implode("\n", (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()));

        self::assertMatchesRegularExpression('/created_by VARCHAR\(128\)[^,]*COLLATE `?utf8mb4_0900_bin`?/', $sql);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('platforms')]
    public function testBinaryCollationPerPlatform(array $params, ?string $expected): void
    {
        $connection = DriverManager::getConnection($params);

        self::assertSame($expected, TablePrefixSubscriber::binaryCollation($connection));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'mysql 8' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], 'utf8mb4_0900_bin'];
        yield 'mariadb' => [['driver' => 'pdo_mysql', 'serverVersion' => '10.11.2-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'mysql without version' => [['driver' => 'mysqli'], 'utf8mb4_0900_bin'];
        yield 'postgresql' => [['driver' => 'pdo_pgsql', 'serverVersion' => '16'], null];
        yield 'sqlite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    /**
     * @return list<string>
     */
    private function createSql(bool $folders): array
    {
        $em = $this->entityManager($folders, ['driver' => 'pdo_sqlite', 'memory' => true]);

        return (new SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function entityManager(bool $folders, array $params): EntityManager
    {
        if (($params['driver'] ?? '') === 'pdo_sqlite' && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        /** @var array{driver: 'pdo_sqlite'} $params */
        $em = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', false, false, $folders));

        return $em;
    }
}
