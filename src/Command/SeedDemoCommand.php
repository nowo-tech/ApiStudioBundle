<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Nowo\ApiStudioBundle\Service\DemoSeedService;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nowo:api-studio:seed-demo',
    description: 'Seed demo workspace, environments, services and endpoints',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly DemoSeedService $demoSeedService,
        private readonly ?ApiStudioTenantContext $tenantContext = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Remove existing data before seeding (with tenancy: the tenant\'s workspaces only)');
        $this->addOption('tenant-type', null, InputOption::VALUE_REQUIRED, 'Tenant type to seed into (tenancy on; default: instance tenant)', '');
        $this->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'Tenant id to seed into (tenancy on)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $fresh  = (bool) $input->getOption('fresh');
        $tenant = new ApiStudioTenant((string) $input->getOption('tenant-type'), (string) $input->getOption('tenant-id'));
        if ($this->tenantContext instanceof ApiStudioTenantContext) {
            $this->tenantContext->runAs($tenant, fn () => $this->demoSeedService->seed($fresh));
        } else {
            $this->demoSeedService->seed($fresh);
        }
        $io->success('Demo API Studio data seeded.');

        return Command::SUCCESS;
    }
}
