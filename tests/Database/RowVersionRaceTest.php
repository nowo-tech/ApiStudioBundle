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
use Nowo\ApiStudioBundle\Doctrine\DefaultEnvironmentSwitch;
use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Doctrine\RowVersionListener;
use Nowo\ApiStudioBundle\Doctrine\WorkspaceImportUnit;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Nowo\ApiStudioBundle\Model\ImportResult;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableImporter;
use Nowo\ApiStudioBundle\Tests\Database\Support\SecondConnection;
use Nowo\ApiStudioBundle\Tests\Support\RowVersionEditProbe;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_map;
use function getenv;
use function is_string;
use function sprintf;
use function ucfirst;

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

        // Moved on, and the entity holds the stored value. The environment moves once per written row: its new variable,
        // plus its own update when addVariable()'s touch() counts as a change (updated_at within the same second may not).
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
        $stored = (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_environment WHERE id = ?', [$environment->getId()]);
        self::assertGreaterThanOrEqual(2, $stored);
        self::assertSame($stored, $environment->getRowVersion());

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

    /**
     * Audit ZH-M1: the edit locks the parent (claim) then a child (its flush); another writer holds the child and then
     * bumps the parent. InnoDB rolls back the lighter transaction (the edit) and, on DBAL 4, the flush's savepoint is
     * gone (1305): the save repairs the nesting level, rethrows the deadlock (409 + CONFLICT_001), leaves nothing
     * half-saved, and the next save on the connection works. PostgreSQL is the control: either side may lose there.
     */
    #[DataProvider('servers')]
    public function testADeadlockInTheEditFlushIsARetrySafeConflict(string $variable): void
    {
        $this->boot($variable);
        [$workspace, $service, $endpoint] = $this->graph();
        $translation                      = $endpoint->getTranslation('en');
        self::assertInstanceOf(ApiEndpointTranslation::class, $translation);
        $mysql = !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        // The child row first, plus two more rows so InnoDB picks the edit (one row) as the victim.
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_endpoint_translation SET title = 'Other writer' WHERE id = %d", $translation->getId())));
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_workspace SET name = 'Other writer' WHERE id = %d", $workspace->getId())));
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_service SET name = 'Other writer' WHERE id = %d", $service->getId())));
        $other->sleep(1000);
        // Then the parent, while the edit waits for the child: the lock cycle.
        $other->send(sprintf('UPDATE api_studio_endpoint SET row_version = row_version + 1 WHERE id = %d', $endpoint->getId()));

        $endpoint->setName('Edited');
        $translation->setTitle('Edited');
        $failure = null;
        try {
            self::assertTrue($this->save($endpoint, 1));
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertSame('OK', $other->await());
        $otherAnswer = $other->await();
        self::assertSame('OK', $other->execute($otherAnswer === 'OK' ? 'COMMIT' : 'ROLLBACK'));

        // Whatever happened, the connection is back at level 0 and usable.
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));

        if ($mysql) {
            self::assertNotNull($failure, 'InnoDB rolls back the lighter transaction: the edit.');
        }
        if ($failure !== null) {
            // The edit lost: 409 + CONFLICT_001 (nothing committed), nothing of it stored, the other writer's work kept.
            // A real lock conflict, not a stale edit (the other writer never committed before the claim): it keeps the
            // retryable transaction code, unlike the domain stale-edit answer.
            self::assertNotInstanceOf(StaleEditException::class, $failure);
            $request = new Request();
            $request->attributes->set('_route', 'nowo_api_studio_endpoint_edit');
            $subscriber = new ConcurrencyConflictSubscriber();
            self::assertTrue($subscriber->isConflict($failure), $failure::class . ': ' . $failure->getMessage());
            [$status, $headers] = $subscriber->answerFor($request, $failure);
            self::assertSame(409, $status);
            self::assertSame('CONFLICT_001', $headers['X-Conflict-Code']);
            self::assertSame('OK', $otherAnswer);
            self::assertSame('Other writer', $this->connection->fetchOne('SELECT title FROM api_studio_endpoint_translation WHERE id = ?', [$translation->getId()]));
            self::assertNotSame('Edited', $this->connection->fetchOne('SELECT name FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
        } else {
            // PostgreSQL may abort the other writer instead: the edit is stored whole.
            self::assertNotSame('OK', $otherAnswer);
            self::assertSame('Edited', $this->connection->fetchOne('SELECT title FROM api_studio_endpoint_translation WHERE id = ?', [$translation->getId()]));
            self::assertSame('Edited', $this->connection->fetchOne('SELECT name FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
        }

        // The next save on the same connection works (a fresh manager: a failed flush closes the ORM's one).
        $manager = $this->freshManager();
        $stored  = $manager->find(ApiEndpoint::class, $endpoint->getId());
        self::assertInstanceOf(ApiEndpoint::class, $stored);
        $stored->setName('Next save');
        self::assertTrue($this->save($stored, $stored->getRowVersion(), manager: $manager));
        self::assertSame('Next save', $this->connection->fetchOne('SELECT name FROM api_studio_endpoint WHERE id = ?', [$endpoint->getId()]));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * Audit ZH-L1: two edits that each make their environment the default run one after the other (the workspace row
     * is locked first) and the second clears the first: one default, never two.
     */
    #[DataProvider('servers')]
    public function testTwoNewDefaultsLeaveOneDefault(string $variable): void
    {
        $this->boot($variable);
        [$workspace, , , $current] = $this->graph();
        $current->setIsDefault(true);
        $first  = (new ApiEnvironment('First', 'first'))->setWorkspace($workspace);
        $second = (new ApiEnvironment('Second', 'second'))->setWorkspace($workspace);
        $this->manager->persist($first);
        $this->manager->persist($second);
        $this->manager->flush();

        // Edit A (second session) makes "first" the default with the same protocol and holds the locks for a while.
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET name = name WHERE id = %d', $workspace->getId())));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_environment SET is_default = %s, row_version = row_version + 1 WHERE workspace_id = %d AND id <> %d AND is_default = %s', $this->bool(false), $workspace->getId(), $first->getId(), $this->bool(true))));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_environment SET is_default = %s, row_version = row_version + 1 WHERE id = %d', $this->bool(true), $first->getId())));
        $other->sleep(700);
        $other->send('COMMIT');

        // Edit B makes "second" the default: it waits for the workspace lock, then clears "first".
        $second->setIsDefault(true);
        self::assertTrue($this->save($second, $second->getRowVersion(), fn () => DefaultEnvironmentSwitch::makeDefault($this->manager, $second)));
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        $defaults = $this->connection->fetchFirstColumn(sprintf('SELECT id FROM api_studio_environment WHERE workspace_id = %d AND is_default = %s', $workspace->getId(), $this->bool(true)));
        self::assertSame([(int) $second->getId()], array_map('intval', $defaults));
        $this->assertUsable();
    }

    /**
     * 1.21.1 (audit ZH-L1 on the create page): two creates that each make their new environment the default. The
     * other create (second session) locks the workspace, clears the defaults, inserts its default and holds the locks;
     * this create waits for the workspace lock, then clears the other one: one default, never two.
     */
    #[DataProvider('servers')]
    public function testTwoCreatesThatSetADefaultLeaveOneDefault(string $variable): void
    {
        $this->boot($variable);
        [$workspace, , , $current] = $this->graph();
        $current->setIsDefault(true);
        $this->manager->flush();
        $workspaceId = (int) $workspace->getId();

        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET name = name WHERE id = %d', $workspaceId)));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_environment SET is_default = %s, row_version = row_version + 1 WHERE workspace_id = %d AND is_default = %s', $this->bool(false), $workspaceId, $this->bool(true))));
        self::assertSame('OK', $other->execute($this->insertEnvironment($workspaceId, 'other', true)));
        $other->sleep(700);
        $other->send('COMMIT');

        $created = $this->createEnvironment($workspace, 'created', true);
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertSame([(int) $created->getId()], $this->defaults($workspaceId));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM api_studio_environment WHERE slug = 'other'"));
        $this->assertUsable();
    }

    /**
     * 1.21.1: an edit that makes an environment the default (second session, the edit page's protocol: workspace lock,
     * clear the others, claim its own row) racing a create of a new default: the create waits, then clears the edit's
     * default. One default.
     */
    #[DataProvider('servers')]
    public function testACreateRacingAnEditThatSetsADefaultLeavesOneDefault(string $variable): void
    {
        $this->boot($variable);
        [$workspace, , , $current] = $this->graph();
        $current->setIsDefault(true);
        $edited = (new ApiEnvironment('Edited', 'edited'))->setWorkspace($workspace);
        $this->manager->persist($edited);
        $this->manager->flush();
        $workspaceId = (int) $workspace->getId();

        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET name = name WHERE id = %d', $workspaceId)));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_environment SET is_default = %s, row_version = row_version + 1 WHERE workspace_id = %d AND id <> %d AND is_default = %s', $this->bool(false), $workspaceId, $edited->getId(), $this->bool(true))));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_environment SET is_default = %s, row_version = row_version + 1 WHERE id = %d AND row_version = 1', $this->bool(true), $edited->getId())));
        $other->sleep(700);
        $other->send('COMMIT');

        $created = $this->createEnvironment($workspace, 'created', true);
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertSame([(int) $created->getId()], $this->defaults($workspaceId));
        $this->assertUsable();
    }

    /**
     * 1.21.1: a create that cannot get the workspace lock fails retry-safe: 409 + CONFLICT_001 + Retry-After on an Api
     * Studio route, nothing inserted, connection usable.
     */
    #[DataProvider('servers')]
    public function testALockWaitTimeoutOnACreateIsARetrySafeConflict(string $variable): void
    {
        $this->boot($variable);
        [$workspace] = $this->graph();
        $other       = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET name = name WHERE id = %d', $workspace->getId())));
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->createEnvironment($workspace, 'blocked', true);
            self::fail('Expected a lock wait timeout.');
        } catch (Throwable $failure) {
            $request = new Request();
            $request->attributes->set('_route', 'nowo_api_studio_environment_new');
            [$status, $headers] = (new ConcurrencyConflictSubscriber())->answerFor($request, $failure);
            self::assertTrue((new ConcurrencyConflictSubscriber())->isConflict($failure));
            self::assertSame(409, $status);
            self::assertSame('CONFLICT_001', $headers['X-Conflict-Code']);
            self::assertArrayHasKey('Retry-After', $headers);
        }

        self::assertSame('OK', $other->execute('COMMIT'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM api_studio_environment WHERE slug = 'blocked'"));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }

    /**
     * 1.21.1: two imports of the same environment into one workspace. The other import (second session) holds the
     * workspace lock and inserts the environment; this import waits for the lock, then reads that row and merges into
     * it: one environment, the variable in it, no unique violation.
     */
    #[DataProvider('servers')]
    public function testTwoImportsOfTheSameEnvironmentLeaveOneRow(string $variable): void
    {
        $this->boot($variable);
        [$workspace] = $this->graph();
        $workspaceId = (int) $workspace->getId();

        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET name = name WHERE id = %d', $workspaceId)));
        self::assertSame('OK', $other->execute($this->insertEnvironment($workspaceId, 'staging', false)));
        $other->sleep(700);
        $other->send('COMMIT');

        // What the import page does: the workspace loaded before the unit, the import inside it.
        $manager = $this->freshManager();
        $loaded  = $manager->find(ApiWorkspace::class, $workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $loaded);
        $result = WorkspaceImportUnit::run($manager, $loaded, null, fn (): ImportResult => (new EnvironmentVariableImporter(new DocumentParser(), $manager))
            ->importIntoWorkspace($loaded, $this->stagingExport(), 'merge', 'environments.json'));
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertSame(0, $result->environmentsCreated);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM api_studio_environment WHERE slug = 'staging'"));
        self::assertSame(1, (int) $this->connection->fetchOne(sprintf("SELECT COUNT(*) FROM api_studio_environment_variable v JOIN api_studio_environment e ON e.id = v.environment_id WHERE e.slug = 'staging' AND e.workspace_id = %d", $workspaceId)));
        $this->assertUsable();
    }

    /**
     * 1.21.1: a writer below the workspace (an environment edit page saving a variable, which locks the environment
     * row, not the workspace) stores the same variable while the import into that environment runs: the unique key
     * refuses the import's row, the unit rolls back to level 0, the failure is retryable (the import page answers 409 +
     * Retry-After) and the retry merges into the stored variable. (A writer that inserts a row of the workspace itself,
     * a service or an environment, already waits on the workspace lock: its foreign key check locks the workspace
     * row, see testTwoImportsOfTheSameEnvironmentLeaveOneRow.).
     */
    #[DataProvider('servers')]
    public function testAnImportRefusedByAConcurrentRowIsRetryableAndItsRetryMerges(string $variable): void
    {
        $this->boot($variable);
        [$workspace] = $this->graph();
        $workspaceId = (int) $workspace->getId();
        $this->connection->executeStatement($this->insertEnvironment($workspaceId, 'staging', false));
        $stagingId = (int) $this->connection->fetchOne("SELECT id FROM api_studio_environment WHERE slug = 'staging'");

        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf("INSERT INTO api_studio_environment_variable (id, environment_id, variable_key, value, secret) VALUES (%s, %d, 'base_url', 'https://other.example.test', %s)", $this->nextId(ApiEnvironmentVariable::class, 'api_studio_environment_variable'), $stagingId, $this->bool(false))));
        $other->sleep(700);
        $other->send('COMMIT');

        $manager = $this->freshManager();
        $loaded  = $manager->find(ApiWorkspace::class, $workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $loaded);
        $failure = null;
        try {
            WorkspaceImportUnit::run($manager, $loaded, null, fn (): ImportResult => (new EnvironmentVariableImporter(new DocumentParser(), $manager))
                ->importIntoWorkspace($loaded, $this->stagingExport(), 'merge', 'environments.json'));
        } catch (Throwable $e) {
            $failure = $e;
        }
        self::assertInstanceOf(Throwable::class, $failure, 'Expected the unique key to refuse the import.');
        self::assertTrue(WorkspaceImportUnit::isRetryable($failure), $failure::class . ': ' . $failure->getMessage());
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM api_studio_environment_variable WHERE environment_id = ?', [$stagingId]));

        // The retry (a new request: a fresh manager) reads the stored variable and merges into it.
        $retry  = $this->freshManager();
        $loaded = $retry->find(ApiWorkspace::class, $workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $loaded);
        $result = WorkspaceImportUnit::run($retry, $loaded, null, fn (): ImportResult => (new EnvironmentVariableImporter(new DocumentParser(), $retry))
            ->importIntoWorkspace($loaded, $this->stagingExport(), 'merge', 'environments.json'));
        self::assertSame(0, $result->variablesCreated);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM api_studio_environment_variable WHERE environment_id = ?', [$stagingId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM api_studio_environment WHERE slug = 'staging'"));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * The create page's save (ApiEnvironmentController::new(), RowVersionEditTrait::saveNew()) on this session.
     */
    private function createEnvironment(ApiWorkspace $workspace, string $slug, bool $default): ApiEnvironment
    {
        $environment = (new ApiEnvironment(ucfirst($slug), $slug))->setIsDefault($default);
        $workspace->addEnvironment($environment);
        if ($default) {
            foreach ($workspace->getEnvironments() as $loaded) {
                if ($loaded !== $environment) {
                    $loaded->setIsDefault(false);
                }
            }
        }
        $this->manager->persist($environment);
        $form = Forms::createFormFactory()->createBuilder(FormType::class, $environment, ['data_class' => ApiEnvironment::class])->getForm();
        self::assertTrue($this->probe($this->manager)->create($form, fn () => DefaultEnvironmentSwitch::prepareCreate($this->manager, $workspace, $default)));

        return $environment;
    }

    /**
     * The id of a row the second session inserts with plain SQL: on PostgreSQL with DBAL 3 the ORM draws ids from a
     * sequence (the column has no default), so it is drawn here too; elsewhere the column generates it (`DEFAULT`).
     *
     * @param class-string $class
     */
    private function nextId(string $class, string $table): string
    {
        $sequence = $this->manager->getClassMetadata($class)->sequenceGeneratorDefinition['sequenceName'] ?? null;
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform || !is_string($sequence) || $sequence === ''
            || $this->connection->fetchOne('SELECT column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [$table, 'id']) !== null) {
            return 'DEFAULT';
        }

        return (string) (int) $this->connection->fetchOne(sprintf("SELECT nextval('%s')", $sequence));
    }

    /**
     * The environment row as the ORM would insert it.
     */
    private function insertEnvironment(int $workspaceId, string $slug, bool $default): string
    {
        return sprintf(
            "INSERT INTO api_studio_environment (id, workspace_id, name, slug, is_default, created_at, updated_at, row_version) VALUES (%s, %d, '%s', '%s', %s, '2026-01-01 00:00:00', '2026-01-01 00:00:00', 1)",
            $this->nextId(ApiEnvironment::class, 'api_studio_environment'),
            $workspaceId,
            ucfirst($slug),
            $slug,
            $this->bool($default),
        );
    }

    /**
     * @return list<int>
     */
    private function defaults(int $workspaceId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(sprintf('SELECT id FROM api_studio_environment WHERE workspace_id = %d AND is_default = %s ORDER BY id', $workspaceId, $this->bool(true))));
    }

    /** A workspace export with one environment "staging" and one variable. */
    private function stagingExport(): string
    {
        $source  = new ApiWorkspace('Source', 'source');
        $staging = new ApiEnvironment('Staging', 'staging');
        $staging->addVariable(new ApiEnvironmentVariable('base_url', 'https://staging.example.test'));
        $source->addEnvironment($staging);

        return (new EnvironmentVariableExporter(new DocumentParser()))->renderWorkspace($source, 'json');
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
        $this->manager    = $this->freshManager();
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
    private function save(RowVersionedInterface $row, int $expected, ?callable $beforeClaim = null, ?EntityManagerInterface $manager = null): bool
    {
        return $this->probe($manager ?? $this->manager)->save($this->form($row, $expected), $row, $beforeClaim);
    }

    /**
     * The edit form as the pages build it: the hidden version, submitted with the version the form was rendered from.
     *
     * @return FormInterface<mixed>
     */
    private function form(RowVersionedInterface $row, int $expected): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $row, ['data_class' => $row::class]);
        RowVersionField::add($builder, [RowVersionField::OPTION => $expected]);
        $form = $builder->getForm();
        $form->submit([RowVersionField::NAME => (string) $expected]);

        return $form;
    }

    /**
     * The edit pages' save (RowVersionEditTrait::saveEdit()) on a minimal controller.
     */
    private function probe(EntityManagerInterface $manager): RowVersionEditProbe
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(new ConcurrencyConflictSubscriber());

        return new RowVersionEditProbe($manager, $container);
    }

    private function freshManager(): EntityManagerInterface
    {
        $manager = $this->scopeManager($this->connection);
        $manager->getEventManager()->addEventListener([Events::postUpdate, Events::postPersist, Events::postRemove], new RowVersionListener());

        return $manager;
    }

    private function bool(bool $value): string
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $value ? 'true' : 'false';
        }

        return $value ? '1' : '0';
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
