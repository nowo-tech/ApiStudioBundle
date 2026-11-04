<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function preg_match;

/**
 * After the move to per-tenant slugs: strips the `-2`, `-3`… suffixes a host added to keep slugs unique across the
 * instance, when the base slug is free inside the tenant. Slugs are not part of any URL, so only exports change name.
 * `--dry-run` lists the renames without writing.
 */
#[AsCommand(
    name: 'nowo:api-studio:tenant:unsuffix-slugs',
    description: 'Strip numeric -N slug suffixes inside a tenant when the base slug is free (tenancy on)',
)]
final class TenantUnsuffixSlugsCommand extends Command
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
        $this
            ->addOption('tenant-type', null, InputOption::VALUE_REQUIRED, 'Tenant type', '')
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'Tenant id', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the renames without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $manager = $this->tenantManager();
        $tenant  = new ApiStudioTenant((string) $input->getOption('tenant-type'), (string) $input->getOption('tenant-id'));

        /** @var list<ApiWorkspace> $workspaces */
        $workspaces = $manager->createQueryBuilder()
            ->select('w')
            ->from(ApiWorkspace::class, 'w')
            ->where('w.tenantType = :type AND w.tenantId = :id')
            ->setParameter('type', $tenant->type)
            ->setParameter('id', $tenant->id)
            ->orderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();

        $taken = [];
        foreach ($workspaces as $workspace) {
            $taken[$workspace->getSlug()] = true;
        }

        $renames = [];
        foreach ($workspaces as $workspace) {
            if (preg_match('/^(.+)-\d+$/', $workspace->getSlug(), $match) !== 1 || isset($taken[$match[1]])) {
                continue;
            }

            $renames[] = [(int) $workspace->getId(), $workspace->getSlug(), $match[1]];
            unset($taken[$workspace->getSlug()]);
            $taken[$match[1]] = true;
            if ($input->getOption('dry-run') !== true) {
                $workspace->setSlug($match[1]);
            }
        }

        if ($renames === []) {
            $io->success('No suffixed slug can be shortened.');

            return Command::SUCCESS;
        }

        $io->table(['Id', 'Slug', 'New slug'], array_map(static fn (array $r): array => [(string) $r[0], $r[1], $r[2]], $renames));
        if ($input->getOption('dry-run') === true) {
            $io->note('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        $manager->flush();
        $io->success('Slugs shortened.');

        return Command::SUCCESS;
    }
}
