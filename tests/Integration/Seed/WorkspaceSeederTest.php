<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Seed;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceSeededEvent;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Seed\EndpointSeed;
use Nowo\ApiStudioBundle\Seed\EnvironmentSeed;
use Nowo\ApiStudioBundle\Seed\SecretReferenceSeed;
use Nowo\ApiStudioBundle\Seed\ServiceSeed;
use Nowo\ApiStudioBundle\Seed\VariableSeed;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeed;
use Nowo\ApiStudioBundle\Seed\WorkspaceSeeder;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function count;
use function json_encode;

/**
 * 1.17 seed API on SQLite: create, idempotent re-run, update, additive, secret references only, per (tenant, scope)
 * identity, refusals, the retry of a unique violation / transient failure, nested transactions and the
 * nothing-logged rule. The real-database races are in tests/Database.
 */
final class WorkspaceSeederTest extends TestCase
{
    use ScopeOrmTrait;

    private ScopeManagerRegistry $registry;

    private EventDispatcher $dispatcher;

    /** @var array<int, ApiWorkspaceSeededEvent> */
    private array $events = [];

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->registry   = $this->sqliteScopeRegistry();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(ApiWorkspaceSeededEvent::class, function (ApiWorkspaceSeededEvent $event): void {
            // After the commit: the workspace is readable from a fresh read.
            self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());
            $this->events[] = $event;
        });
        $this->logger = new InMemoryLogger();
    }

    public function testCreatesTheWholeTreeThenARerunChangesNothing(): void
    {
        $seed   = $this->seed();
        $result = $this->seeder()->seed($seed);

        self::assertTrue($result->workspaceCreated);
        self::assertSame(1, $result->attempts);
        self::assertSame(['service' => 2, 'endpoint' => 3, 'environment' => 2, 'variable' => 4], $result->created);
        self::assertSame([], $result->updated);
        self::assertCount(1, $this->events);
        self::assertTrue($this->events[0]->created);
        self::assertSame('project:alpha', $this->events[0]->scope);
        self::assertSame($result->workspaceId, $this->events[0]->workspaceId);

        $again = $this->seeder()->seed($seed);
        self::assertFalse($again->workspaceCreated);
        self::assertSame($result->workspaceId, $again->workspaceId);
        self::assertSame([], $again->created);
        self::assertSame(['service' => 2, 'endpoint' => 3, 'environment' => 2, 'variable' => 4], $again->updated);
        self::assertSame([1, 2, 3, 2, 4], $this->counts());
        self::assertFalse($this->events[1]->created);

        $this->em()->clear();
        $workspace = $this->em()->find(ApiWorkspace::class, $result->workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        self::assertSame('Insurers', $workspace->getName());
        self::assertSame('project:alpha', $workspace->getScope()?->value);
        self::assertTrue($workspace->getTenant()->equals(new ApiStudioTenant('organization', 'acme')));
        $service = $this->all($this->em(), ApiService::class, 'mapfre')[0] ?? null;
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame('https://api.example.test', $service->getBaseUrl());
        self::assertSame(ApiProtocol::Rest, $service->getProtocol());
        $endpoint = $this->all($this->em(), ApiEndpoint::class, 'quote')[0] ?? null;
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);
        self::assertSame(HttpMethod::Post, $endpoint->getMethod());
        self::assertSame('/v1/quotes', $endpoint->getPath());
    }

    public function testSecretsAreStoredAsReferencesOnlyAndNothingIsLogged(): void
    {
        $this->seeder()->seed($this->seed());
        $this->em()->clear();

        $variables = [];
        foreach ($this->all($this->em(), ApiEnvironmentVariable::class) as $variable) {
            $variables[$variable->getEnvironment()?->getSlug() . '.' . $variable->getVariableKey()] = [$variable->getValue(), $variable->isSecret()];
        }
        self::assertSame(['https://api.example.test', false], $variables['prod.base_url']);
        self::assertSame(['{{secret:vault/core/mapfre/api-key}}', true], $variables['prod.api_key']);

        $logged = (string) json_encode($this->logger->records);
        self::assertStringNotContainsString('vault/core', $logged);
        self::assertStringNotContainsString('api.example.test', $logged);
        self::assertStringContainsString('workspace seeded', $logged);
    }

    public function testAnUpdateChangesWhatTheSeedListsAndKeepsEverythingElse(): void
    {
        $first = $this->seeder()->seed($this->seed());

        // Something the seed does not list, added from the studio.
        $workspace = $this->em()->find(ApiWorkspace::class, $first->workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        $workspace->addService(new ApiService('Manual', 'manual'));
        $this->em()->flush();
        $this->em()->clear();

        $seed = new WorkspaceSeed('insurers', 'Insurers v2', new ApiStudioTenant('organization', 'acme'), new WorkspaceScope('project:alpha'), 'Updated', [
            new ServiceSeed('mapfre', 'Mapfre v2', 'https://v2.example.test', ApiProtocol::Graphql, null, [new EndpointSeed('quote', 'Quote v2', HttpMethod::Put, '/v2/quotes')]),
        ], [
            new EnvironmentSeed('dev', 'Development', true, [new VariableSeed('api_key', 'plain-now')]),
        ]);
        $result = $this->seeder()->seed($seed);
        self::assertSame(['service' => 1, 'endpoint' => 1, 'environment' => 1, 'variable' => 1], $result->updated);
        self::assertSame([], $result->created);

        $this->em()->clear();
        $workspace = $this->em()->find(ApiWorkspace::class, $first->workspaceId);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        self::assertSame('Insurers v2', $workspace->getName());
        self::assertSame('Updated', $workspace->getDescription());
        self::assertCount(3, $workspace->getServices());
        $defaults = [];
        foreach ($workspace->getEnvironments() as $environment) {
            $defaults[$environment->getSlug()] = $environment->isDefault();
            foreach ($environment->getVariables() as $variable) {
                if ($environment->getSlug() === 'dev' && $variable->getVariableKey() === 'api_key') {
                    // A seeded plain variable replaces the reference: value and secret flag follow the seed.
                    self::assertSame('plain-now', $variable->getValue());
                    self::assertFalse($variable->isSecret());
                }
            }
        }
        // One default environment: the seed's.
        self::assertSame(['dev' => true, 'prod' => false], $defaults);
        $endpoint = $this->all($this->em(), ApiEndpoint::class, 'quote')[0] ?? null;
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);
        self::assertSame(HttpMethod::Put, $endpoint->getMethod());
    }

    public function testTheSameSlugInAnotherScopeOrTenantIsAnotherWorkspace(): void
    {
        $alpha = $this->seeder()->seed(new WorkspaceSeed('insurers', 'A', new ApiStudioTenant('organization', 'acme'), new WorkspaceScope('project:alpha')));
        $beta  = $this->seeder()->seed(new WorkspaceSeed('insurers', 'B', new ApiStudioTenant('organization', 'acme'), new WorkspaceScope('project:beta')));
        $org   = $this->seeder()->seed(new WorkspaceSeed('insurers', 'O', new ApiStudioTenant('organization', 'acme')));
        $other = $this->seeder()->seed(new WorkspaceSeed('insurers', 'Z', new ApiStudioTenant('organization', 'zeta'), new WorkspaceScope('project:alpha')));
        $upper = $this->seeder()->seed(new WorkspaceSeed('insurers', 'U', new ApiStudioTenant('organization', 'acme'), new WorkspaceScope('project:ALPHA')));
        $ids   = [$alpha->workspaceId, $beta->workspaceId, $org->workspaceId, $other->workspaceId, $upper->workspaceId];

        self::assertCount(5, array_unique($ids));
        foreach ([$alpha, $beta, $org, $other, $upper] as $result) {
            self::assertTrue($result->workspaceCreated);
        }
    }

    public function testASeedWithAScopeNeedsScopesOn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scope.enabled');
        $this->seeder(scopes: false)->seed(new WorkspaceSeed('x', 'X', scope: new WorkspaceScope('project:a')));
    }

    public function testASeedWithATenantNeedsTenancyOn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.enabled');
        $this->seeder(tenancy: false)->seed(new WorkspaceSeed('x', 'X', new ApiStudioTenant('organization', 'acme')));
    }

    public function testSecretReferencesNeedAHostSecretSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secrets.source');
        $this->seeder(secretSource: false)->seed($this->seed());
    }

    public function testAUniqueViolationOfAConcurrentWriterIsRetriedFromAFreshRead(): void
    {
        $this->failWith(static fn (): Throwable => new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null), 1);

        $result = $this->seeder()->seed($this->seed());
        self::assertSame(2, $result->attempts);
        self::assertTrue($result->workspaceCreated);
        self::assertSame([1, 2, 3, 2, 4], $this->counts());
        self::assertCount(1, $this->events);
    }

    public function testAPersistentUniqueViolationIsRethrownAfterTheLastAttempt(): void
    {
        $this->failWith(static fn (): Throwable => new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null), 99);

        try {
            $this->seeder()->seed($this->seed());
            self::fail('Expected the unique violation.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame([0, 0, 0, 0, 0], $this->counts());
        self::assertSame([], $this->events);
    }

    public function testTransientFailuresAreRetriedThenBecomeAConcurrencyConflict(): void
    {
        $this->failWith(static fn (): Throwable => new TransientStorageFailure(2), WorkspaceSeeder::MAX_ATTEMPTS);

        try {
            $this->seeder()->seed($this->seed());
            self::fail('Expected a concurrency conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(WorkspaceSeeder::MAX_ATTEMPTS, $conflict->attempts);
            self::assertSame(TransientFailureKind::Deadlock, $conflict->kind);
            self::assertSame(2, $conflict->retryAfterSeconds());
            self::assertFalse($conflict->committed);
        }
        self::assertSame([0, 0, 0, 0, 0], $this->counts());
        // The connection is usable afterwards.
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel());
        self::assertTrue($this->seeder()->seed($this->seed())->workspaceCreated);
    }

    public function testAConcurrencyConflictFromBelowIsPassedOnAsIs(): void
    {
        $inner = new ConcurrencyConflict(3, TransientFailureKind::LockWaitTimeout, 1, new RuntimeException('lock wait'));
        $this->failWith(static fn (): Throwable => $inner, 99);

        try {
            $this->seeder()->seed($this->seed());
            self::fail('Expected a concurrency conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame($inner, $conflict);
        }
    }

    public function testInsideACallersTransactionItRunsOnceAndPropagates(): void
    {
        $this->failWith(static fn (): Throwable => new TransientStorageFailure(1), 1);
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        try {
            $this->seeder()->seed($this->seed());
            self::fail('Expected the conflict at once.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(1, $conflict->attempts);
        } finally {
            $connection->rollBack();
        }
    }

    public function testItRefusesToRunOverPendingChangesOfTheManager(): void
    {
        $this->em()->persist(new ApiWorkspace('Pending', 'pending'));

        $this->expectException(LogicException::class);
        $this->seeder()->seed($this->seed());
    }

    public function testWithoutABackoffOrADispatcherItStillWorks(): void
    {
        $this->failWith(static fn (): Throwable => new TransientStorageFailure(1), 1);
        $seeder = new WorkspaceSeeder($this->registry, null, true, true, true, null, null, backoffMicroseconds: 0);

        self::assertSame(2, $seeder->seed($this->seed())->attempts);
    }

    private function seeder(bool $scopes = true, bool $tenancy = true, bool $secretSource = true): WorkspaceSeeder
    {
        return new WorkspaceSeeder($this->registry, null, $scopes, $tenancy, $secretSource, $this->dispatcher, $this->logger, backoffMicroseconds: 1);
    }

    private function seed(): WorkspaceSeed
    {
        return new WorkspaceSeed(
            'insurers',
            'Insurers',
            new ApiStudioTenant('organization', 'acme'),
            new WorkspaceScope('project:alpha'),
            null,
            [
                new ServiceSeed('mapfre', 'Mapfre', 'https://api.example.test', ApiProtocol::Rest, 'Quotes', [
                    new EndpointSeed('quote', 'Quote', HttpMethod::Post, '/v1/quotes'),
                    new EndpointSeed('policy', 'Policy', HttpMethod::Get, '/v1/policies/{{id}}'),
                ]),
                new ServiceSeed('axa', 'Axa', '{{base_url}}', ApiProtocol::Soap, null, [new EndpointSeed('rate', 'Rate', HttpMethod::Post, '/rate', 'text/xml')]),
            ],
            [
                new EnvironmentSeed('dev', 'Development', false, [new VariableSeed('base_url', 'https://sandbox.example.test')], [new SecretReferenceSeed('api_key', 'vault', 'core/mapfre/sandbox-key')]),
                new EnvironmentSeed('prod', 'Production', true, [new VariableSeed('base_url', 'https://api.example.test', 'Public')], [new SecretReferenceSeed('api_key', 'vault', 'core/mapfre/api-key', 'Key')]),
            ],
        );
    }

    /**
     * Makes the first `$times` workspace inserts fail with `$failure` (a stand-in for a concurrent writer). The
     * listener is added to every manager the registry builds, also after a reset.
     *
     * @param callable(): Throwable $failure
     */
    private function failWith(callable $failure, int $times): void
    {
        $remaining = $times;
        $listener  = new class($failure, $remaining) {
            /**
             * @param callable(): Throwable $failure
             */
            public function __construct(private $failure, public int $remaining)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->remaining > 0 && $args->getObject() instanceof ApiWorkspace) {
                    --$this->remaining;

                    throw ($this->failure)();
                }
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::prePersist], $listener);
        $this->registry->onReset = static function (EntityManagerInterface $manager) use ($listener): void {
            $manager->getEventManager()->addEventListener([Events::prePersist], $listener);
        };
    }

    /**
     * @return list<int> workspaces, services, endpoints, environments, variables
     */
    private function counts(): array
    {
        $em = $this->em();

        return [
            count($this->all($em, ApiWorkspace::class)),
            count($this->all($em, ApiService::class)),
            count($this->all($em, ApiEndpoint::class)),
            count($this->all($em, ApiEnvironment::class)),
            count($this->all($em, ApiEnvironmentVariable::class)),
        ];
    }

    private function em(): EntityManagerInterface
    {
        return $this->scopeEntityManager($this->registry);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param string|null $slug only the rows with this slug
     *
     * @return list<T>
     */
    private function all(EntityManagerInterface $em, string $class, ?string $slug = null): array
    {
        $query = $em->createQueryBuilder()->select('e')->from($class, 'e');
        if ($slug !== null) {
            $query->where('e.slug = :slug')->setParameter('slug', $slug);
        }
        /** @var list<T> $rows */
        $rows = $query->getQuery()->getResult();

        return $rows;
    }
}
