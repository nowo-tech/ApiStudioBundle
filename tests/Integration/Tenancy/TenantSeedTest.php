<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Tenancy;

use Nowo\ApiStudioBundle\Command\SeedDemoCommand;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\DemoSeedService;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tests\Support\TenantOrmTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * seed-demo with tenancy: seeds into the given tenant; --fresh removes only that tenant's workspaces.
 */
final class TenantSeedTest extends TestCase
{
    use TenantOrmTrait;

    public function testSeedIntoATenant(): void
    {
        $em       = $this->createTenantEntityManager();
        $registry = $this->tenantRegistry($em);
        $context  = new ApiStudioTenantContext(null, true);
        $this->tenantWorkspace($em, 'beta', 'kept');
        $command = new SeedDemoCommand(new DemoSeedService($em, new ApiWorkspaceRepository($registry), new ApiServiceRepository($registry), $context), $context);
        $tester  = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--tenant-type' => 'org', '--tenant-id' => 'acme']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--tenant-type' => 'org', '--tenant-id' => 'acme', '--fresh' => true]));
        $em->clear();

        $rows = $em->createQueryBuilder()->select('w.slug', 'w.tenantId')->from(ApiWorkspace::class, 'w')->orderBy('w.slug')->getQuery()->getArrayResult();
        self::assertSame([['slug' => 'demo', 'tenantId' => 'acme'], ['slug' => 'kept', 'tenantId' => 'beta']], $rows);
    }
}
