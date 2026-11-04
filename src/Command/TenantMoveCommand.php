<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceMovedEvent;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function ctype_digit;
use function is_string;
use function sprintf;

/**
 * Moves a workspace (with everything under it) to another tenant: CLI only, never from the UI. Refused when the
 * destination tenant already has the workspace's slug. `--dry-run` reports without writing.
 */
#[AsCommand(
    name: 'nowo:api-studio:tenant:move',
    description: 'Move a workspace to another tenant (tenancy on)',
)]
final class TenantMoveCommand extends Command
{
    use TenantCommandTrait;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ApiStudioTenantContext $tenantContext,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workspace', InputArgument::REQUIRED, 'Workspace id')
            ->addOption('to-type', null, InputOption::VALUE_REQUIRED, 'Destination tenant type (empty with --to-id empty: instance tenant)', '')
            ->addOption('to-id', null, InputOption::VALUE_REQUIRED, 'Destination tenant id', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $manager = $this->tenantManager();
        $id      = $input->getArgument('workspace');
        if (!is_string($id) || !ctype_digit($id)) {
            throw new InvalidArgumentException('The workspace argument must be a numeric id.');
        }

        $workspace = $manager->find(ApiWorkspace::class, (int) $id);
        if (!$workspace instanceof ApiWorkspace) {
            throw new RuntimeException(sprintf('Workspace %s not found.', $id));
        }

        $from = $workspace->getTenant();
        $to   = new ApiStudioTenant((string) $input->getOption('to-type'), (string) $input->getOption('to-id'));
        if ($from->equals($to)) {
            $io->note('The workspace already belongs to that tenant.');

            return Command::SUCCESS;
        }

        $taken = (int) $manager->createQueryBuilder()
            ->select('COUNT(w.id)')
            ->from(ApiWorkspace::class, 'w')
            ->where('w.tenantType = :type AND w.tenantId = :id AND w.slug = :slug')
            ->setParameter('type', $to->type)
            ->setParameter('id', $to->id)
            ->setParameter('slug', $workspace->getSlug())
            ->getQuery()
            ->getSingleScalarResult();
        if ($taken > 0) {
            $io->error(sprintf('The destination tenant already has a workspace with the slug "%s": rename one of them first.', $workspace->getSlug()));

            return Command::FAILURE;
        }

        $summary = sprintf('Workspace %d "%s": "%s" → "%s".', (int) $workspace->getId(), $workspace->getSlug(), $from->key(), $to->key());
        if ($input->getOption('dry-run') === true) {
            $io->note('Dry run. ' . $summary);

            return Command::SUCCESS;
        }

        // DQL UPDATE: an entity refuses to change tenant (ApiWorkspace::assignTenant()).
        $manager->createQueryBuilder()
            ->update(ApiWorkspace::class, 'w')
            ->set('w.tenantType', ':type')
            ->set('w.tenantId', ':id')
            ->where('w.id = :workspace')
            ->setParameter('type', $to->type)
            ->setParameter('id', $to->id)
            ->setParameter('workspace', (int) $workspace->getId())
            ->getQuery()
            ->execute();
        $manager->refresh($workspace);

        $this->eventDispatcher?->dispatch(new ApiWorkspaceMovedEvent((int) $workspace->getId(), $from, $to));
        $io->success($summary);

        return Command::SUCCESS;
    }
}
