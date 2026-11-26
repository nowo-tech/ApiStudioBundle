<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioHistoryRetention;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioPersonalData;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Tests\Database\Support\SecondConnection;
use Nowo\ApiStudioBundle\Tests\Support\PrivacyOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function getenv;
use function is_string;
use function rawurlencode;
use function sprintf;

/**
 * 1.24 REQ-RUNTIME-003 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the history purge deletes by primary
 * key in short batches and never waits for a live writer of recent rows; the GDPR erasure and export compare the
 * actor byte-exact (binary NO PAD column, and a PHP re-check for an older case-insensitive column); a lock conflict
 * is retried standalone, propagates at once inside a caller's transaction, and leaves the connection usable at the
 * right nesting level. Runs only when AS_MYSQL_URL, AS_MARIADB_URL and AS_POSTGRES_URL are set (CI job "Databases").
 */
final class HistoryPrivacyDatabaseTest extends TestCase
{
    use PrivacyOrmTrait;

    private const RECENT = '2026-11-25 10:00:00';

    private Connection $connection;

    private ScopeManagerRegistry $registry;

    private ?SecondConnection $other = null;

    private string $url = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        yield 'MySQL 8.4' => ['AS_MYSQL_URL'];
        yield 'MariaDB 11.4' => ['AS_MARIADB_URL'];
        yield 'PostgreSQL 16' => ['AS_POSTGRES_URL'];
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->registry)) {
            // The folder table references the service table: leave no table the other suites do not map.
            $em = $this->privacyEntityManager($this->registry);
            (new SchemaTool($em))->dropSchema($em->getMetadataFactory()->getAllMetadata());
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[DataProvider('servers')]
    public function testThePurgeNeverWaitsForALiveWriter(string $variable): void
    {
        $endpoint = $this->boot($variable);
        $em       = $this->privacyEntityManager($this->registry);
        for ($i = 0; $i < 25; ++$i) {
            $this->addHistory($em, $endpoint, 'u:ana', 'https://api.example.test/old', '2001-01-01 00:00:00');
        }
        $recent = $this->addHistory($em, $endpoint, 'u:ana', 'https://api.example.test/recent', self::RECENT);

        // A live writer holds the recent row and an uncommitted new row until after the purge.
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_request_history SET response_status = 500 WHERE id = %d', $recent)));
        self::assertSame('OK', $other->execute($this->insertHistorySql(900001, (int) $endpoint->getId(), 'u:bob')));
        $this->shortLockWait();

        $result = (new DoctrineApiStudioHistoryRetention($this->privacyResolver($this->registry), 90, 10))->purge();

        self::assertSame([25, 3, true], [$result->deleted, $result->batches, $result->complete]);
        self::assertSame('OK', $other->execute('COMMIT'));
        self::assertSame(2, $this->countRows($this->connection, 'request_history'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testErasureAndExportAreByteExactOnTheServer(string $variable): void
    {
        $endpoint = $this->boot($variable);
        $em       = $this->privacyEntityManager($this->registry);
        $service  = $endpoint->getService();
        self::assertNotNull($service);
        foreach (['u:UserA', 'u:usera', 'u:UserA '] as $actor) {
            $this->addHistory($em, $endpoint, $actor, 'https://api.example.test/' . $actor);
            $this->addFolder($em, $service, $actor, 'Folder ' . $actor);
        }
        $ports = $this->ports();

        self::assertSame(['https://api.example.test/u:usera'], array_column($ports->export('u:usera')->history, 'url'));
        self::assertSame(['Folder u:usera'], array_column($ports->export('u:usera')->folders, 'name'));
        $result = $ports->erase('u:UserA');

        self::assertSame([1, 1], [$result->historyRowsDeleted, $result->foldersAnonymised]);
        self::assertEqualsCanonicalizing(['u:usera', 'u:UserA '], $this->connection->fetchFirstColumn('SELECT executed_by FROM api_studio_request_history'));
        self::assertEqualsCanonicalizing(['u:usera', 'u:UserA '], $this->connection->fetchFirstColumn('SELECT created_by FROM api_studio_folder WHERE created_by IS NOT NULL'));
        $this->assertUsable();
    }

    /**
     * Audit ZCL-H1: default actor ids carry the raw identifier (spaces, non-ASCII); they are erased and exported
     * byte-exact on the server (`u:josé` is not `u:jose` nor `u:JOSÉ`). Characters of the BMP only: with DBAL 3 the
     * test schema's tables default to 3-byte utf8 on MySQL / MariaDB (the folder name column); 4-byte ids are covered
     * on SQLite (tests/Integration/Privacy).
     */
    #[DataProvider('servers')]
    public function testIdsWithSpacesAndNonAsciiAreErasedAndExportedExactly(string $variable): void
    {
        $endpoint = $this->boot($variable);
        $em       = $this->privacyEntityManager($this->registry);
        $service  = $endpoint->getService();
        self::assertNotNull($service);
        foreach (['u:John Smith', 'u:john smith', 'u:josé@example.test', 'u:jose@example.test', 'u:JOSÉ@example.test', 'u:名前', "u:ana\n", 'u:ana', "u:an\ta"] as $actor) {
            $this->addHistory($em, $endpoint, $actor, 'https://api.example.test/' . rawurlencode($actor));
            $this->addFolder($em, $service, $actor, 'Folder ' . $actor);
        }
        $ports = $this->ports();

        foreach (['u:John Smith', 'u:josé@example.test', 'u:名前', "u:ana\n", "u:an\ta"] as $actor) {
            self::assertSame(['https://api.example.test/' . rawurlencode($actor)], array_column($ports->export($actor)->history, 'url'), $actor);
            self::assertSame(['Folder ' . $actor], array_column($ports->export($actor)->folders, 'name'), $actor);
            $result = $ports->erase($actor);
            self::assertSame([1, 1], [$result->historyRowsDeleted, $result->foldersAnonymised], $actor);
        }

        self::assertEqualsCanonicalizing(['u:john smith', 'u:jose@example.test', 'u:JOSÉ@example.test', 'u:ana'], $this->connection->fetchFirstColumn('SELECT executed_by FROM api_studio_request_history'));
        self::assertEqualsCanonicalizing(['u:john smith', 'u:jose@example.test', 'u:JOSÉ@example.test', 'u:ana'], $this->connection->fetchFirstColumn('SELECT created_by FROM api_studio_folder WHERE created_by IS NOT NULL'));
        $this->assertUsable();
    }

    /**
     * An install whose actor column still has a case-insensitive collation (before 1.24's migration): the server
     * returns look-alike rows, the PHP re-check drops them.
     */
    #[DataProvider('servers')]
    public function testThePhpRecheckProtectsAnOlderCaseInsensitiveColumn(string $variable): void
    {
        $endpoint = $this->boot($variable);
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // PostgreSQL compares text exactly whatever the column's history.
            self::assertInstanceOf(PostgreSQLPlatform::class, $this->connection->getDatabasePlatform());

            return;
        }
        $collation = $this->connection->getDatabasePlatform() instanceof MariaDBPlatform ? 'utf8mb4_general_ci' : 'utf8mb4_0900_ai_ci';
        $this->connection->executeStatement(sprintf('ALTER TABLE api_studio_request_history MODIFY executed_by VARCHAR(64) COLLATE %s NULL', $collation));
        $em = $this->privacyEntityManager($this->registry);
        $this->addHistory($em, $endpoint, 'u:UserA', 'https://api.example.test/upper');
        $this->addHistory($em, $endpoint, 'u:usera', 'https://api.example.test/lower');
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM api_studio_request_history WHERE executed_by = 'u:usera'"), 'the server matches both');

        self::assertSame(['https://api.example.test/lower'], array_column($this->ports()->export('u:usera')->history, 'url'));
        self::assertSame(1, $this->ports()->erase('u:usera')->historyRowsDeleted);
        self::assertSame(['u:UserA'], $this->connection->fetchFirstColumn('SELECT executed_by FROM api_studio_request_history'));
    }

    #[DataProvider('servers')]
    public function testAStandaloneErasureRetriesALockConflictThenReportsIt(string $variable): void
    {
        $endpoint = $this->boot($variable);
        $em       = $this->privacyEntityManager($this->registry);
        $row      = $this->addHistory($em, $endpoint, 'u:ana');
        $other    = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_request_history SET response_status = 500 WHERE id = %d', $row)));
        $this->shortLockWait();

        try {
            $this->ports()->erase('u:ana');
            self::fail('Expected a lock conflict.');
        } catch (TransientStorageFailure) {
        }

        self::assertSame('OK', $other->execute('COMMIT'));
        self::assertSame(1, $this->countRows($this->connection, 'request_history'), 'nothing half-erased');
        $this->assertUsable();
        self::assertSame(1, $this->ports()->erase('u:ana')->historyRowsDeleted, 'a retry later succeeds');
    }

    #[DataProvider('servers')]
    public function testInsideACallersTransactionALockConflictPropagatesAtTheCallersLevel(string $variable): void
    {
        $endpoint = $this->boot($variable);
        $em       = $this->privacyEntityManager($this->registry);
        $row      = $this->addHistory($em, $endpoint, 'u:ana');
        $other    = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_request_history SET response_status = 500 WHERE id = %d', $row)));
        $this->shortLockWait();

        $this->connection->beginTransaction();

        try {
            $this->ports()->erase('u:ana');
            self::fail('Expected a lock conflict.');
        } catch (TransientStorageFailure) {
            // Repaired to the caller's level (or the connection closed when the server dropped the savepoint).
            self::assertLessThanOrEqual(1, $this->connection->getTransactionNestingLevel());
        }
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        self::assertSame('OK', $other->execute('COMMIT'));
        $this->assertUsable();
        self::assertSame(1, $this->countRows($this->connection, 'request_history'));
    }

    private function boot(string $variable): ApiEndpoint
    {
        $url = getenv($variable);
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', $variable));
        }

        $this->url        = $url;
        $parser           = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url), $this->privacyConfiguration());
        $this->registry   = $this->privacyRegistry($this->connection);
        // Bounded waits: a test that blocks fails within seconds instead of hanging the job.
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '15s'" : 'SET SESSION innodb_lock_wait_timeout = 15');

        return $this->seedEndpoint($this->privacyEntityManager($this->registry))[1];
    }

    private function shortLockWait(): void
    {
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }

    private function ports(): DoctrineApiStudioPersonalData
    {
        return new DoctrineApiStudioPersonalData($this->privacyResolver($this->registry), new HistorySanitizer(), 'api_studio_', null, 10);
    }

    private function other(): SecondConnection
    {
        return $this->other = new SecondConnection($this->url);
    }

    private function begin(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BEGIN' : 'START TRANSACTION';
    }

    /**
     * Explicit id: with DBAL 3 the ORM maps ids to a sequence on PostgreSQL (no column default).
     */
    private function insertHistorySql(int $id, int $endpointId, string $actor): string
    {
        $true = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'true' : '1';

        return sprintf(
            "INSERT INTO api_studio_request_history (id, endpoint_id, executed_at, executed_by, request_url, request_method, request_headers, response_headers, success) VALUES (%d, %d, '%s', '%s', 'https://api.example.test/live', 'GET', '[]', '[]', %s)",
            $id,
            $endpointId,
            self::RECENT,
            $actor,
            $true,
        );
    }

    private function assertUsable(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
        self::assertTrue($this->privacyEntityManager($this->registry)->isOpen());
    }
}
