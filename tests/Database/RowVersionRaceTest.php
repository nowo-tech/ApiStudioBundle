<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Doctrine\RowVersionListener;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Tests\Database\Support\SecondConnection;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function getenv;
use function is_string;
use function sprintf;

/**
 * 1.20 REQ-RUNTIME-003 rule 4 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the row version of the edit
 * forms (workspace, service, endpoint, environment). Two editors racing on the same version (exactly one saves, the
 * other gets a stale edit and writes nothing), a lock wait timeout on the claim (409 + CONFLICT_001, connection
 * usable), writers without a version moving it atomically, and child rows (translations, variables) moving their
 * parent's. A second session in its own process holds the locks. Runs only when AS_MYSQL_URL, AS_MARIADB_URL and
 * AS_POSTGRES_URL are set (CI job "Databases", `make test-with-db`); the job fails on any skipped test.
 */
final class RowVersionRaceTest extends TestCase
{
    use ScopeOrmTrait;

    private Connection $connection;

    private EntityManagerInterface $manager;

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
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[DataProvider('servers')]
    public function testTheSecondEditorOnTheSameVersionWaitsThenWritesNothing(string $variable): void
    {
        $this->boot($variable);
        [$workspace] = $this->graph();
        $id          = (int) $workspace->getId();
        self::assertSame(1, $workspace->getRowVersion());

        // Editor A (second session) saves its form rendered at version 1 and holds the row lock until the COMMIT.
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_workspace SET row_version = row_version + 1, name = 'Editor A' WHERE id = %d AND row_version = 1", $id)));
        $other->sleep(700);
        $other->send('COMMIT');

        // Editor B, also rendered at version 1: its claim waits for A's lock, then matches no row.
        $workspace->setName('Editor B');
        try {
            $this->save($workspace, 1);
            self::fail('Expected a stale edit.');
        } catch (StaleEditException) {
        }
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertSame('Editor A', $this->connection->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_workspace WHERE id = ?', [$id]));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testTheFirstEditorSavesAndTheSecondMatchesNoRow(string $variable): void
    {
        $this->boot($variable);
        [, $service] = $this->graph();
        $id          = (int) $service->getId();

        // Editor A (this session) claims and flushes, holding the lock; editor B's guarded UPDATE waits for it.
        $service->setName('Editor A');
        $this->connection->beginTransaction();
        RowVersionGuard::claim($this->manager, $service, 1);
        $this->manager->flush();
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        $other->send(sprintf("UPDATE api_studio_service SET row_version = row_version + 1, name = 'Editor B' WHERE id = %d AND row_version = 1", $id));
        $this->connection->commit();
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->execute('COMMIT'));

        // B's statement re-read the row after A's commit (MySQL / MariaDB current read, PostgreSQL re-check): no lost update.
        self::assertSame('Editor A', $this->connection->fetchOne('SELECT name FROM api_studio_service WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_service WHERE id = ?', [$id]));
        self::assertSame(2, $service->getRowVersion());
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testALockWaitTimeoutOnTheClaimIsARetrySafeConflict(string $variable): void
    {
        $this->boot($variable);
        [, , $endpoint] = $this->graph();
        $id             = (int) $endpoint->getId();
        $other          = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_endpoint SET enabled = enabled WHERE id = %d', $id)));
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');

        $endpoint->setName('Blocked');
        try {
            $this->save($endpoint, 1);
            self::fail('Expected a lock wait timeout.');
        } catch (Throwable $failure) {
            self::assertNotInstanceOf(StaleEditException::class, $failure);
            // Its answer on an Api Studio route: 409 + CONFLICT_001 + Retry-After (nothing was committed).
            $request = new Request();
            $request->attributes->set('_route', 'nowo_api_studio_endpoint_edit');
            [$status, $headers] = (new ConcurrencyConflictSubscriber())->answerFor($request, $failure);
            self::assertTrue((new ConcurrencyConflictSubscriber())->isConflict($failure));
            self::assertSame(409, $status);
            self::assertSame('CONFLICT_001', $headers['X-Conflict-Code']);
            self::assertArrayHasKey('Retry-After', $headers);
        }

        self::assertSame('OK', $other->execute('COMMIT'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_endpoint WHERE id = ?', [$id]));
        self::assertNotSame('Blocked', $this->connection->fetchOne('SELECT name FROM api_studio_endpoint WHERE id = ?', [$id]));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }

    #[DataProvider('servers')]
    public function testWritersWithoutAVersionMoveItAtomically(string $variable): void
    {
        $this->boot($variable);
        [$workspace] = $this->graph();
        $id          = (int) $workspace->getId();

        // Another writer moved the version after this manager loaded the row (it still holds 1).
        self::assertSame('OK', $this->other()->execute(sprintf('UPDATE api_studio_workspace SET row_version = row_version + 1 WHERE id = %d', $id)));
        $workspace->setDescription('From the seed API');
        $this->manager->flush();

        // row_version = row_version + 1 on the stored value: 3, never a second 2.
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_workspace WHERE id = ?', [$id]));
        self::assertSame(3, $workspace->getRowVersion());

        // A form rendered at 2 is refused; one rendered at 3 saves.
        $workspace->setName('Stale form');
        try {
            $this->save($workspace, 2);
            self::fail('Expected a stale edit.');
        } catch (StaleEditException) {
        }
        $this->manager->refresh($workspace);
        $workspace->setName('Current form');
        $this->save($workspace, 3);
        self::assertSame('Current form', $this->connection->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$id]));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_workspace WHERE id = ?', [$id]));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testChildRowsMoveTheirParentsVersion(string $variable): void
    {
        $this->boot($variable);
        [, , $endpoint, $environment] = $this->graph();

        // Documentation of the endpoint (a translation row) and a variable of the environment, written without a version.
        $translation = $endpoint->getTranslation('en');
        self::assertInstanceOf(ApiEndpointTranslation::class, $translation);
        $translation->setTitle('Changed elsewhere');
        $environment->addVariable(new ApiEnvironmentVariable('token', 'x'));
        $this->manager->flush();

        // Moved on (by one per written row: the environment's own update and its new variable move it twice).
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_environment WHERE id = ?', [$environment->getId()]));
        self::assertSame(3, $environment->getRowVersion());

        // An edit form rendered before them is refused, nothing written.
        $environment->setName('Stale');
        try {
            $this->save($environment, 1);
            self::fail('Expected a stale edit.');
        } catch (StaleEditException) {
        }
        self::assertNotSame('Stale', $this->connection->fetchOne('SELECT name FROM api_studio_environment WHERE id = ?', [$environment->getId()]));
        $this->assertUsable();
    }

    private function boot(string $variable): void
    {
        $url = getenv($variable);
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', $variable));
        }

        $this->url        = $url;
        $parser           = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url), $this->scopeConfiguration());
        $this->manager    = $this->scopeManager($this->connection);
        $this->manager->getEventManager()->addEventListener([Events::postUpdate, Events::postPersist, Events::postRemove], new RowVersionListener());
        $this->createScopeSchema($this->manager);
        // Bounded waits: a test that blocks fails within seconds instead of hanging the job.
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '15s'" : 'SET SESSION innodb_lock_wait_timeout = 15');
    }

    /**
     * A workspace with a service, an endpoint (English documentation) and an environment, all at version 1.
     *
     * @return array{ApiWorkspace, ApiService, ApiEndpoint, ApiEnvironment}
     */
    private function graph(): array
    {
        $workspace   = new ApiWorkspace('Insurers', 'insurers');
        $service     = (new ApiService('Mapfre', 'mapfre'))->setWorkspace($workspace);
        $endpoint    = (new ApiEndpoint('Quote', 'quote'))->setService($service);
        $environment = (new ApiEnvironment('Production', 'prod'))->setWorkspace($workspace);
        $endpoint->addTranslation((new ApiEndpointTranslation('en'))->setTitle('Quote'));
        foreach ([$workspace, $service, $endpoint, $environment] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();

        return [$workspace, $service, $endpoint, $environment];
    }

    /**
     * What the edit pages do (RowVersionEditTrait::saveEdit()): one transaction, claim at the form's version, flush.
     */
    private function save(ApiWorkspace|ApiService|ApiEndpoint|ApiEnvironment $row, int $expected): void
    {
        $this->connection->beginTransaction();
        try {
            RowVersionGuard::claim($this->manager, $row, $expected);
            $this->manager->flush();
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function other(): SecondConnection
    {
        return $this->other = new SecondConnection($this->url);
    }

    private function begin(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BEGIN' : 'START TRANSACTION';
    }

    private function assertUsable(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
        self::assertTrue($this->manager->isOpen());
    }
}
