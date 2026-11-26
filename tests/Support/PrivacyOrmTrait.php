<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.24 GDPR / retention tests: entity managers with the history actor column and the folder table switchable (as
 * `history.scope` and `folders.enabled` do), on SQLite in memory or any connection (the real-database suite).
 */
trait PrivacyOrmTrait
{
    private function privacyConfiguration(): Configuration
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return $config;
    }

    private function privacyManager(Connection $connection, bool $historyActor = true, bool $folders = true): EntityManagerInterface
    {
        $em = new EntityManager($connection, $this->privacyConfiguration(), new EventManager());
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', false, $historyActor, $folders));

        return $em;
    }

    private function sqliteConnection(): Connection
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->privacyConfiguration());
    }

    /**
     * A registry over $connection (SQLite in memory by default) whose manager maps the history actor and the folders
     * as asked; the schema is (re)created unless $createSchema is false.
     */
    private function privacyRegistry(?Connection $connection = null, bool $historyActor = true, bool $folders = true, bool $createSchema = true): ScopeManagerRegistry
    {
        $registry = new ScopeManagerRegistry($connection ?? $this->sqliteConnection(), fn (Connection $connection): EntityManagerInterface => $this->privacyManager($connection, $historyActor, $folders));
        if ($createSchema) {
            $em       = $this->privacyEntityManager($registry);
            $tool     = new SchemaTool($em);
            $metadata = $em->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        return $registry;
    }

    private function privacyEntityManager(ScopeManagerRegistry $registry): EntityManagerInterface
    {
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function privacyResolver(ScopeManagerRegistry $registry): ApiStudioEntityManagerResolver
    {
        return new ApiStudioEntityManagerResolver($registry);
    }

    /**
     * @return array{ApiService, ApiEndpoint}
     */
    private function seedEndpoint(EntityManagerInterface $em): array
    {
        $workspace = new ApiWorkspace('Payments', 'payments');
        $service   = (new ApiService('Orders', 'orders'))->setWorkspace($workspace);
        $endpoint  = (new ApiEndpoint('List orders', 'list-orders'))->setService($service);
        $em->persist($workspace);
        $em->persist($service);
        $em->persist($endpoint);
        $em->flush();

        return [$service, $endpoint];
    }

    /**
     * One history row; $executedAt (Y-m-d H:i:s) is written afterwards (the entity sets it to now).
     */
    private function addHistory(EntityManagerInterface $em, ApiEndpoint $endpoint, ?string $actor, string $url = 'https://api.example.test/orders', ?string $executedAt = null, ?string $body = null): int
    {
        $history = (new ApiRequestHistory())
            ->setEndpoint($endpoint)
            ->setExecutedBy($actor)
            ->setRequestUrl($url)
            ->setRequestMethod('POST')
            ->setRequestHeaders(['X-Trace' => 'trace-header'])
            ->setRequestBody($body)
            ->setResponseStatus(201)
            ->setResponseHeaders(['X-Answer' => 'answer-header'])
            ->setResponseBody($body)
            ->setErrorMessage($body)
            ->setSuccess(true);
        $em->persist($history);
        $em->flush();
        $id = (int) $history->getId();
        if ($executedAt !== null) {
            $em->getConnection()->executeStatement('UPDATE api_studio_request_history SET executed_at = ? WHERE id = ?', [$executedAt, $id]);
        }

        return $id;
    }

    private function addFolder(EntityManagerInterface $em, ApiService $service, ?string $createdBy, string $name = 'Invoices'): int
    {
        $folder = (new ApiFolder($name))->setService($service)->setCreatedBy($createdBy);
        $em->persist($folder);
        $em->flush();

        return (int) $folder->getId();
    }

    private function countRows(Connection $connection, string $table, string $where = '1 = 1'): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM api_studio_' . $table . ' WHERE ' . $where);
    }
}
