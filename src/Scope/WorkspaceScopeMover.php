<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceScopeChangedEvent;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * The host changes the scope of a workspace (1.17), e.g. moves a tenant-level workspace into a project. One
 * transaction (transaction-doctrine runner on the Api Studio manager), the row read `FOR UPDATE`, the unique key
 * (tenant, scope, slug) deciding a slug collision in the target ({@see WorkspaceSlugTakenException}, nothing changed).
 * {@see ApiWorkspaceScopeChangedEvent} after the commit. A transient failure propagates as the runner's
 * `TransientStorageFailure` (one attempt: the caller decides whether to retry). No UI, no route: the caller authorises.
 * Stateless (worker-safe).
 */
final readonly class WorkspaceScopeMover
{
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $managerName = null,
        private bool $scopes = false,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function move(int $workspaceId, ?WorkspaceScope $to): void
    {
        if (!$this->scopes) {
            throw new LogicException('Api Studio: workspace scopes are disabled (nowo_api_studio.scope.enabled).');
        }

        $managers = new ApiStudioEntityManagerResolver($this->registry, $this->managerName);
        $manager  = $managers->entityManager();
        try {
            $change = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry, $managers->managerName(), 1, $this->logger)
                ->run(static function () use ($manager, $workspaceId, $to): ?array {
                    $workspace = $manager->find(ApiWorkspace::class, $workspaceId, LockMode::PESSIMISTIC_WRITE);
                    if (!$workspace instanceof ApiWorkspace) {
                        throw new InvalidArgumentException(sprintf('Api Studio: no workspace %d.', $workspaceId));
                    }
                    // A copy the manager already held is not re-read by the locking find: read the locked row (audit UA).
                    $manager->refresh($workspace);

                    $from = $workspace->getScope();
                    if (WorkspaceScope::key($from) === WorkspaceScope::key($to)) {
                        return null;
                    }

                    $workspace->assignScope($to);
                    $manager->flush();

                    return [$workspace->getTenant(), $from?->value];
                });
        } catch (UniqueConstraintViolationException $e) {
            throw new WorkspaceSlugTakenException($workspaceId, $e);
        }

        if ($change === null) {
            return;
        }

        $this->logger?->info('Api Studio: workspace scope changed.', ['workspace_id' => $workspaceId]);
        $this->dispatcher?->dispatch(new ApiWorkspaceScopeChangedEvent($workspaceId, $change[0], $change[1], $to?->value));
    }
}
