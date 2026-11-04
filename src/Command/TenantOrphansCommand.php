<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Read-only: workspaces still in the instance tenant `('', '')` while tenancy is on (created before the tenant backfill,
 * or from the CLI / seeds without a tenant). No resolver returns the instance tenant in a multi-tenant host, so nobody
 * sees them until they are moved (`nowo:api-studio:tenant:move`).
 */
#[AsCommand(
    name: 'nowo:api-studio:tenant:orphans',
    description: 'List the workspaces that belong to no tenant (tenancy on)',
)]
final class TenantOrphansCommand extends Command
{
    use TenantCommandTrait;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ApiStudioTenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON (id, slug, name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<array{id: int, slug: string, name: string}> $rows */
        $rows = $this->tenantManager()->createQueryBuilder()
            ->select('w.id AS id', 'w.slug AS slug', 'w.name AS name')
            ->from(ApiWorkspace::class, 'w')
            ->where("w.tenantType = '' AND w.tenantId = ''")
            ->orderBy('w.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        if ($rows === []) {
            $io->success('Every workspace belongs to a tenant.');

            return Command::SUCCESS;
        }

        $io->table(['Id', 'Slug', 'Name'], $rows);
        $io->warning(count($rows) . ' workspace(s) belong to no tenant: move them with nowo:api-studio:tenant:move.');

        return Command::SUCCESS;
    }
}
