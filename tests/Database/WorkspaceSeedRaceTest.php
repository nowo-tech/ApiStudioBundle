<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceScopeChangedEvent;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Scope\WorkspaceSlugTakenException;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Seed\EndpointSeed;
use Nowo\ApiStudioBundle\Seed\EnvironmentSeed;
use Nowo\ApiStudioBundle\Seed\ServiceSeed;
use Nowo\ApiStudioBundle\Seed\VariableSeed;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeed;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeeder;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Database\Support\SecondConnection;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function getenv;
use function is_string;
use function sprintf;

/**
 * 1.17 REQ-RUNTIME-003 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): two sessions race on the seed's
 * unique key (tenant, scope, slug), on a child row and on the workspace lock; a lock wait timeout becomes a
 * CONFLICT_001 conflict and the connection stays usable; scopes compare byte-exact on the server. Runs only when
 * AS_MYSQL_URL, AS_MARIADB_URL and AS_POSTGRES_URL are set (CI job "Databases", `make test-with-db`); the job fails
 * on any skipped test.
 */
final class WorkspaceSeedRaceTest extends TestCase
{
    use ScopeOrmTrait;

    private const NOW = '2026-11-18 00:00:00';

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
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[DataProvider('servers')]
    public function testTwoWritersRaceForTheSameNewWorkspace(string $variable): void
    {
        $this->boot($variable);
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute($this->insertWorkspaceSql('Other writer')));
        // Commit while the seed below is blocked on the unique key.
        $other->sleep(700);
        $other->send('COMMIT');

        $result = $this->seeder()->seed($this->seed());
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        // The loser of the race read again and updated the winner's row: one workspace, the seed's content.
        self::assertSame(2, $result->attempts);
        self::assertFalse($result->workspaceCreated);
        self::assertSame(1, $this->rows('workspace'));
        self::assertSame(1, $this->rows('service'));
        self::assertSame(2, $this->rows('endpoint'));
        self::assertSame('Insurers', $this->connection->fetchOne('SELECT name FROM api_studio_workspace'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testTwoSeedsOfTheSameWorkspaceRunOneAfterTheOther(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed($this->seed());
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        // The UPDATE takes the row lock (a SELECT … FOR UPDATE would leave a result set open in the helper).
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_workspace SET name = 'Other writer' WHERE id = %d", $first->workspaceId)));
        $other->sleep(700);
        $other->send('COMMIT');

        $result = $this->seeder()->seed($this->seed('Insurers v2'));
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        // It waited for the lock, then wrote last: no lost update of its own fields, no duplicate.
        self::assertSame(1, $result->attempts);
        self::assertSame('Insurers v2', $this->connection->fetchOne('SELECT name FROM api_studio_workspace'));
        self::assertSame(1, $this->rows('workspace'));
        self::assertSame(1, $this->rows('service'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testAChildRowInsertedConcurrentlyIsNotDuplicated(string $variable): void
    {
        $this->boot($variable);
        $workspace = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Insurers', $this->tenant(), $this->scope()));
        $other     = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf(
            "INSERT INTO api_studio_service (id, workspace_id, name, slug, base_url, protocol, auth_type, auth_config, default_headers, enabled, created_at, updated_at) VALUES (900002, %d, 'Other', 'mapfre', 'https://other.example.test', 'rest', 'none', '[]', '[]', %s, '%s', '%s')",
            $workspace->workspaceId,
            $this->true(),
            self::NOW,
            self::NOW,
        )));
        $other->sleep(700);
        $other->send('COMMIT');

        $result = $this->seeder()->seed($this->seed());
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        // The workspace lock is the first read, so the seed reads the committed child row: one attempt (audit UA3-1).
        self::assertSame(1, $result->attempts);
        self::assertSame(1, $this->rows('service'));
        self::assertSame('https://api.example.test', $this->connection->fetchOne('SELECT base_url FROM api_studio_service'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testALockWaitTimeoutBecomesARetrySafeConflict(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed($this->seed());
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        // Row lock held until the COMMIT below (an UPDATE: a SELECT … FOR UPDATE would leave a result set open in the helper).
        self::assertSame('OK', $other->execute(sprintf('UPDATE api_studio_workspace SET enabled = enabled WHERE id = %d', $first->workspaceId)));
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->seeder()->seed($this->seed('Blocked'));
            self::fail('Expected a concurrency conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(WorkspaceSeeder::MAX_ATTEMPTS, $conflict->attempts);
            self::assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
            self::assertFalse($conflict->committed);

            // Its HTTP answer on an Api Studio route: 409 + CONFLICT_001 + Retry-After.
            $request = new Request();
            $request->attributes->set('_route', 'nowo_api_studio_scoped_workspace_edit');
            $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $conflict);
            (new ConcurrencyConflictSubscriber())->onKernelException($event);
            $answer = $event->getThrowable();
            self::assertInstanceOf(HttpException::class, $answer);
            self::assertSame(409, $answer->getStatusCode());
            self::assertSame('CONFLICT_001', $answer->getHeaders()['X-Conflict-Code']);
            self::assertArrayHasKey('Retry-After', $answer->getHeaders());
        }

        self::assertSame('OK', $other->execute('COMMIT'));
        // Nothing half-saved, and the connection serves the next seed.
        self::assertSame('Insurers', $this->connection->fetchOne('SELECT name FROM api_studio_workspace'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame('Insurers v3', $this->connection->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$this->seeder()->seed($this->seed('Insurers v3'))->workspaceId]));
    }

    #[DataProvider('servers')]
    public function testScopesCompareByteExactOnTheServer(string $variable): void
    {
        $this->boot($variable);
        $lower = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Lower', $this->tenant(), new WorkspaceScope('project:a')));
        $upper = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Upper', $this->tenant(), new WorkspaceScope('project:A')));
        $space = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Pad', $this->tenant(), new WorkspaceScope('project:a.')));

        self::assertTrue($upper->workspaceCreated);
        self::assertTrue($space->workspaceCreated);
        self::assertSame(3, $this->rows('workspace'));

        $repository = new ApiWorkspaceRepository($this->registry);
        $found      = $repository->findInScope(new ApiStudioScope($this->tenant(), true, null, new WorkspaceScope('project:A'), true), 10);
        self::assertCount(1, $found);
        self::assertSame($upper->workspaceId, $found[0]->getId());
        self::assertNotSame($lower->workspaceId, $upper->workspaceId);
    }

    #[DataProvider('servers')]
    public function testMovingIntoAScopeWhereTheSlugIsTakenChangesNothing(string $variable): void
    {
        $this->boot($variable);
        $this->seeder()->seed(new WorkspaceSeed('insurers', 'In project', $this->tenant(), $this->scope()));
        $tenantLevel = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Tenant level', $this->tenant()));

        try {
            (new WorkspaceScopeMover($this->registry, null, true))->move($tenantLevel->workspaceId, $this->scope());
            self::fail('Expected the slug collision.');
        } catch (WorkspaceSlugTakenException) {
        }

        self::assertSame('', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$tenantLevel->workspaceId]));
        $this->assertUsable();
    }

    /**
     * Audit UA-L1: tenant ids compare exactly on the server too (binary NO PAD tenant columns since 1.17).
     */
    #[DataProvider('servers')]
    public function testALookAlikeTenantSeesNothingAndDoesNotCollide(string $variable): void
    {
        $this->boot($variable);
        $victim    = $this->seeder()->seed($this->seed());
        $lookAlike = new ApiStudioTenant('organization', 'ACME');

        $repository = new ApiWorkspaceRepository($this->registry);
        foreach ([new ApiStudioScope($lookAlike, true, null, null, true, true, null), new ApiStudioScope($lookAlike, true, null, $this->scope(), true)] as $view) {
            self::assertSame([], $repository->findInScope($view, 10));
            self::assertSame([], $repository->navigationRows($view));
            self::assertSame(0, $repository->countInScope($view));
        }

        // The same slug and scope for "ACME" is another workspace, not a unique-key collision with "acme".
        $other = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Look-alike', $lookAlike, $this->scope()));
        self::assertTrue($other->workspaceCreated);
        self::assertNotSame($victim->workspaceId, $other->workspaceId);
        self::assertSame('Insurers', $this->connection->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$victim->workspaceId]));
    }

    /**
     * Audit UA-I3: variable keys compare exactly (`API_KEY` and `api_key` are two variables), so a re-seed with
     * another case is no unique-key collision.
     */
    #[DataProvider('servers')]
    public function testVariableKeysCompareExactly(string $variable): void
    {
        $this->boot($variable);
        $this->seeder()->seed($this->seed());
        $result = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Insurers', $this->tenant(), $this->scope(), null, [], [
            new EnvironmentSeed('prod', 'Production', true, [new VariableSeed('BASE_URL', 'https://upper.example.test')]),
        ]));

        self::assertSame(1, $result->attempts);
        self::assertSame(['variable' => 1], $result->created);
        self::assertSame(2, $this->rows('environment_variable'));
    }

    /**
     * Audit UA: a seed whose manager already holds the workspace re-reads it after the lock, so a move committed
     * meanwhile is seen (the seed then creates the workspace in its own scope instead of writing into the moved one).
     */
    #[DataProvider('servers')]
    public function testASeedAfterAConcurrentMoveDoesNotWriteIntoTheMovedWorkspace(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Alpha insurers', $this->tenant(), $this->scope()));
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_workspace SET host_scope = 'project:beta' WHERE id = %d", $first->workspaceId)));
        $other->sleep(700);
        $other->send('COMMIT');

        $result = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Alpha insurers', $this->tenant(), $this->scope(), null, [
            new ServiceSeed('alpha-only', 'Alpha only', 'https://api.example.test'),
        ]));
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertNotSame($first->workspaceId, $result->workspaceId);
        self::assertSame('project:alpha', $this->connection->fetchOne('SELECT w.host_scope FROM api_studio_service s JOIN api_studio_workspace w ON w.id = s.workspace_id'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testTheMoverReadsTheLockedRowNotAStaleCopy(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed(new WorkspaceSeed('insurers', 'W', $this->tenant(), $this->scope()));
        $em    = $this->scopeEntityManager($this->registry);
        self::assertNotNull($em->find(ApiWorkspace::class, $first->workspaceId));
        // Moved by another process; the manager still holds the project:alpha copy.
        $this->connection->executeStatement("UPDATE api_studio_workspace SET host_scope = 'project:beta' WHERE id = ?", [$first->workspaceId]);

        (new WorkspaceScopeMover($this->registry, null, true))->move($first->workspaceId, $this->scope());
        self::assertSame('project:alpha', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$first->workspaceId]));
    }

    /**
     * Audit UA2-1: nested in a caller's transaction that already read (MySQL / MariaDB REPEATABLE READ snapshot), the
     * seed must still see a move committed afterwards: the locking read fills the entity, not a snapshot refresh.
     */
    #[DataProvider('servers')]
    public function testANestedSeedSeesAMoveCommittedAfterTheCallersSnapshot(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Alpha insurers', $this->tenant(), $this->scope()));
        $this->connection->beginTransaction();
        self::assertSame('project:alpha', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$first->workspaceId]));
        self::assertSame('OK', $this->other()->execute(sprintf("UPDATE api_studio_workspace SET host_scope = 'project:beta' WHERE id = %d", $first->workspaceId)));

        $result = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Alpha insurers', $this->tenant(), $this->scope(), null, [
            new ServiceSeed('alpha-only', 'Alpha only', 'https://api.example.test'),
        ]));
        $this->connection->commit();

        self::assertNotSame($first->workspaceId, $result->workspaceId);
        self::assertSame('project:beta', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$first->workspaceId]));
        self::assertSame('project:alpha', $this->connection->fetchOne('SELECT w.host_scope FROM api_studio_service s JOIN api_studio_workspace w ON w.id = s.workspace_id'));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testANestedMoveSeesAMoveCommittedAfterTheCallersSnapshot(string $variable): void
    {
        $this->boot($variable);
        $first = $this->seeder()->seed(new WorkspaceSeed('insurers', 'W', $this->tenant(), $this->scope()));
        $this->connection->beginTransaction();
        self::assertSame('project:alpha', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$first->workspaceId]));
        self::assertSame('OK', $this->other()->execute(sprintf("UPDATE api_studio_workspace SET host_scope = 'project:beta' WHERE id = %d", $first->workspaceId)));

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ApiWorkspaceScopeChangedEvent::class, static function (ApiWorkspaceScopeChangedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        // Already in project:beta: no change, no event with a stale "from".
        (new WorkspaceScopeMover($this->registry, null, true, $dispatcher))->move($first->workspaceId, new WorkspaceScope('project:beta'));
        $this->connection->commit();

        self::assertSame([], $events);
        self::assertSame('project:beta', $this->connection->fetchOne('SELECT host_scope FROM api_studio_workspace WHERE id = ?', [$first->workspaceId]));
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
        $this->registry   = new ScopeManagerRegistry($this->connection, fn (Connection $connection): EntityManagerInterface => $this->scopeManager($connection));
        $this->createScopeSchema($this->scopeEntityManager($this->registry));
        // Bounded waits: a test that blocks fails within seconds instead of hanging the job.
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '15s'" : 'SET SESSION innodb_lock_wait_timeout = 15');
    }

    private function other(): SecondConnection
    {
        return $this->other = new SecondConnection($this->url);
    }

    private function seeder(): WorkspaceSeeder
    {
        return new WorkspaceSeeder($this->registry, null, true, true, false, backoffMicroseconds: 1000);
    }

    private function seed(string $name = 'Insurers'): WorkspaceSeed
    {
        return new WorkspaceSeed('insurers', $name, $this->tenant(), $this->scope(), null, [
            new ServiceSeed('mapfre', 'Mapfre', 'https://api.example.test', endpoints: [
                new EndpointSeed('quote', 'Quote'),
                new EndpointSeed('policy', 'Policy'),
            ]),
        ], [
            new EnvironmentSeed('prod', 'Production', true, [new VariableSeed('base_url', 'https://api.example.test')]),
        ]);
    }

    private function tenant(): ApiStudioTenant
    {
        return new ApiStudioTenant('organization', 'acme');
    }

    private function scope(): WorkspaceScope
    {
        return new WorkspaceScope('project:alpha');
    }

    private function begin(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BEGIN' : 'START TRANSACTION';
    }

    private function true(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'true' : '1';
    }

    /**
     * Explicit ids: with DBAL 3 the ORM maps ids to a sequence on PostgreSQL (no column default).
     */
    private function insertWorkspaceSql(string $name): string
    {
        return sprintf(
            "INSERT INTO api_studio_workspace (id, name, slug, enabled, tenant_type, tenant_id, host_scope, created_at, updated_at) VALUES (900001, '%s', 'insurers', %s, 'organization', 'acme', 'project:alpha', '%s', '%s')",
            $name,
            $this->true(),
            self::NOW,
            self::NOW,
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM api_studio_' . $table);
    }

    private function assertUsable(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
        self::assertTrue($this->scopeEntityManager($this->registry)->isOpen());
    }
}
