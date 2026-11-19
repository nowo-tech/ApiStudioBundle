<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceSeededEvent;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_numeric;
use function random_int;
use function usleep;

/**
 * Creates or updates one workspace from a {@see WorkspaceSeed} (1.17): idempotent by natural keys — workspace by
 * (tenant, scope, slug), service and environment by slug within the workspace, endpoint by slug within its service,
 * variable by key within its environment. Additive: nothing the seed does not list is removed.
 *
 * No secret value can be seeded (secret references only, {@see SecretReferenceSeed}), and nothing of the payload is
 * logged: only ids, counts and attempts.
 *
 * Concurrency (REQ-RUNTIME-003): each attempt is one transaction through transaction-doctrine's runner on the Api
 * Studio manager (`forHostEntityManager()`: never clears or resets an open manager, refuses to start over its pending
 * changes). The workspace is looked up by its natural key without a lock and before the transaction (no gap lock),
 * then re-read `SELECT … FOR UPDATE` by id as the transaction's first read, so seeds of one workspace run one after
 * the other and see each other's rows; a missing one is inserted and the
 * unique key (tenant, scope, slug) decides between two writers. A unique violation (another writer inserted the same
 * key first) or a transient failure (deadlock, lock wait, serialization) rolls the attempt back and the seed runs again
 * from a fresh read, at most {@see self::MAX_ATTEMPTS} times, and only when it opened the outermost transaction; the
 * last transient failure becomes a {@see ConcurrencyConflict} (nothing committed: `CONFLICT_001`). Events go after the
 * commit. Stateless (worker-safe): the manager is fetched per attempt.
 *
 * The caller authorises: the seeder takes the tenant and the scope from the seed, never from a request.
 */
final readonly class WorkspaceSeeder
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private ManagerRegistry $registry,
        private ?string $managerName = null,
        private bool $scopes = false,
        private bool $tenancy = false,
        private bool $secretSource = false,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private TransientFailureClassifier $classifier = new TransientFailureClassifier(),
        /** Pause between two attempts, upper bound in microseconds (full jitter from 0). */
        private int $backoffMicroseconds = 50_000,
    ) {
    }

    public function seed(WorkspaceSeed $seed): WorkspaceSeedResult
    {
        $this->assertSeedable($seed);

        $managers  = new ApiStudioEntityManagerResolver($this->registry, $this->managerName);
        $outermost = $managers->entityManager()->getConnection()->getTransactionNestingLevel() === 0;
        $attempts  = $outermost ? self::MAX_ATTEMPTS : 1;

        for ($attempt = 1;; ++$attempt) {
            $manager = $managers->entityManager();
            $stats   = new SeedStats();
            try {
                // Looked up before the transaction: the lock below is then the first read of the transaction, so on
                // MySQL / MariaDB (REPEATABLE READ) the rows read after it are not older than the lock.
                $existing    = $this->existingId($manager, $seed);
                $workspaceId = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry, $managers->managerName(), 1, $this->logger)
                    ->run(fn (): int => $this->apply($manager, $seed, $existing, $stats));
            } catch (UniqueConstraintViolationException|TransientStorageFailure $failure) {
                $this->logger?->notice('Api Studio seed: attempt failed under contention.', ['attempt' => $attempt, 'max_attempts' => $attempts, 'exception' => $failure::class]);
                if ($attempt >= $attempts) {
                    throw $failure instanceof TransientStorageFailure ? $this->conflict($attempt, $failure) : $failure;
                }
                $this->pause();

                continue;
            }

            $result = new WorkspaceSeedResult($workspaceId, $stats->workspaceCreated, $attempt, $stats->created, $stats->updated);
            $this->logger?->info('Api Studio seed: workspace seeded.', [
                'workspace_id' => $workspaceId,
                'created'      => $stats->workspaceCreated,
                'attempts'     => $attempt,
                'rows_created' => $stats->created,
                'rows_updated' => $stats->updated,
            ]);
            $this->dispatcher?->dispatch(new ApiWorkspaceSeededEvent($workspaceId, $seed->tenant, $seed->scope?->value, $stats->workspaceCreated));

            return $result;
        }
    }

    private function assertSeedable(WorkspaceSeed $seed): void
    {
        if ($seed->scope instanceof WorkspaceScope && !$this->scopes) {
            throw new InvalidArgumentException('Api Studio seed: the seed has a scope but nowo_api_studio.scope.enabled is false.');
        }
        if (!$seed->tenant->isInstance() && !$this->tenancy) {
            throw new InvalidArgumentException('Api Studio seed: the seed has a tenant but nowo_api_studio.tenancy.enabled is false.');
        }
        if (!$this->secretSource && $seed->hasSecretReferences()) {
            throw new InvalidArgumentException('Api Studio seed: secret references need nowo_api_studio.secrets.source (otherwise they would never resolve).');
        }
    }

    /**
     * One attempt, inside the runner's transaction: returns the workspace id (flushed).
     */
    private function apply(EntityManagerInterface $manager, WorkspaceSeed $seed, ?int $existing, SeedStats $stats): int
    {
        $workspace = $existing !== null ? $this->lockedWorkspace($manager, $seed, $existing) : null;
        if (!$workspace instanceof ApiWorkspace) {
            $workspace = (new ApiWorkspace($seed->name, $seed->slug))->assignTenant($seed->tenant)->assignScope($seed->scope);
            $manager->persist($workspace);
            // The unique key (tenant, scope, slug) decides between two writers here.
            $manager->flush();
            $stats->workspaceCreated = true;
        }

        // @igor-ignore - Doctrine entity loaded or built for this attempt, not a shared service.
        $workspace->setName($seed->name)->setDescription($seed->description);
        foreach ($seed->services as $serviceSeed) {
            $this->applyService($workspace, $serviceSeed, $stats);
        }
        foreach ($seed->environments as $environmentSeed) {
            $this->applyEnvironment($workspace, $environmentSeed, $stats);
        }
        $manager->flush();

        return (int) $workspace->getId();
    }

    /**
     * Id of the workspace of the seed's natural key (no lock: a missing row takes no gap lock), null when there is none.
     */
    private function existingId(EntityManagerInterface $manager, WorkspaceSeed $seed): ?int
    {
        $metadata = $manager->getClassMetadata(ApiWorkspace::class);
        $query    = $manager->createQueryBuilder()
            ->select('w.id')
            ->from(ApiWorkspace::class, 'w')
            ->where('w.slug = :slug')
            ->setParameter('slug', $seed->slug)
            ->setMaxResults(1);
        if ($metadata->hasField('tenantType')) {
            $query->andWhere('w.tenantType = :tenant_type AND w.tenantId = :tenant_id')
                ->setParameter('tenant_type', $seed->tenant->type)
                ->setParameter('tenant_id', $seed->tenant->id);
        }
        if ($metadata->hasField('hostScope')) {
            $query->andWhere('w.hostScope = :host_scope')->setParameter('host_scope', WorkspaceScope::key($seed->scope));
        }

        $id = $query->getQuery()->getScalarResult()[0]['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The workspace `$id`, locked for this transaction (`SELECT … FOR UPDATE`), when it still has the seed's natural
     * key (re-checked byte-exact: exact-key rule); null when it went away meanwhile (the seed then inserts it).
     */
    private function lockedWorkspace(EntityManagerInterface $manager, WorkspaceSeed $seed, int $id): ?ApiWorkspace
    {
        // The locking find is the transaction's first read (audit UA3-1): no consistent read happens before the row lock.
        $workspace = $manager->find(ApiWorkspace::class, $id, LockMode::PESSIMISTIC_WRITE);
        if ($workspace instanceof ApiWorkspace) {
            // A locking find does not re-read a copy the manager already held, so the refresh fills the entity from the
            // locked row (audits UA, UA2-1).
            $manager->refresh($workspace, LockMode::PESSIMISTIC_WRITE);
        }

        return $workspace instanceof ApiWorkspace && $workspace->getSlug() === $seed->slug && $workspace->getTenant()->equals($seed->tenant) && $workspace->getScopeKey() === WorkspaceScope::key($seed->scope) ? $workspace : null;
    }

    private function applyService(ApiWorkspace $workspace, ServiceSeed $seed, SeedStats $stats): void
    {
        $service = null;
        foreach ($workspace->getServices() as $candidate) {
            if ($candidate->getSlug() === $seed->slug) {
                $service = $candidate;
                break;
            }
        }
        $stats->count('service', $service instanceof ApiService);
        if (!$service instanceof ApiService) {
            $service = new ApiService($seed->name, $seed->slug);
            $workspace->addService($service);
        }

        $service->setName($seed->name)->setBaseUrl($seed->baseUrl)->setProtocol($seed->protocol)->setDescription($seed->description);
        foreach ($seed->endpoints as $endpointSeed) {
            $endpoint = null;
            foreach ($service->getEndpoints() as $candidate) {
                if ($candidate->getSlug() === $endpointSeed->slug) {
                    $endpoint = $candidate;
                    break;
                }
            }
            $stats->count('endpoint', $endpoint instanceof ApiEndpoint);
            if (!$endpoint instanceof ApiEndpoint) {
                $endpoint = new ApiEndpoint($endpointSeed->name, $endpointSeed->slug);
                $service->addEndpoint($endpoint);
            }
            $endpoint->setName($endpointSeed->name)->setMethod($endpointSeed->method)->setPath($endpointSeed->path)->setContentType($endpointSeed->contentType);
        }
    }

    private function applyEnvironment(ApiWorkspace $workspace, EnvironmentSeed $seed, SeedStats $stats): void
    {
        $environment = null;
        foreach ($workspace->getEnvironments() as $candidate) {
            if ($candidate->getSlug() === $seed->slug) {
                $environment = $candidate;
            } elseif ($seed->isDefault && $candidate->isDefault()) {
                $candidate->setIsDefault(false);
            }
        }
        $stats->count('environment', $environment instanceof ApiEnvironment);
        if (!$environment instanceof ApiEnvironment) {
            $environment = new ApiEnvironment($seed->name, $seed->slug);
            $workspace->addEnvironment($environment);
        }
        $environment->setName($seed->name);
        if ($seed->isDefault) {
            $environment->setIsDefault(true);
        }

        foreach ($seed->variables as $variable) {
            $this->applyVariable($environment, $variable->key, $variable->value, false, $variable->description, $stats);
        }
        foreach ($seed->secretReferences as $reference) {
            $this->applyVariable($environment, $reference->key, $reference->reference(), true, $reference->description, $stats);
        }
    }

    private function applyVariable(ApiEnvironment $environment, string $key, string $value, bool $secret, ?string $description, SeedStats $stats): void
    {
        $variable = null;
        foreach ($environment->getVariables() as $candidate) {
            if ($candidate->getVariableKey() === $key) {
                $variable = $candidate;
                break;
            }
        }
        $stats->count('variable', $variable instanceof ApiEnvironmentVariable);
        if (!$variable instanceof ApiEnvironmentVariable) {
            $variable = new ApiEnvironmentVariable($key);
            $environment->addVariable($variable);
        }
        // Value first: it makes an unreadable secret readable again, so the secret flag can then follow the seed.
        $variable->setValue($value);
        $variable->setSecret($secret);
        $variable->setDescription($description);
    }

    private function conflict(int $attempts, TransientStorageFailure $failure): Throwable
    {
        if ($failure instanceof ConcurrencyConflict) {
            return $failure;
        }

        return new ConcurrencyConflict($attempts, $this->classifier->classify($failure) ?? TransientFailureKind::Deadlock, $failure->retryAfterSeconds(), $failure);
    }

    private function pause(): void
    {
        if ($this->backoffMicroseconds > 0) {
            usleep(random_int(0, $this->backoffMicroseconds));
        }
    }
}
