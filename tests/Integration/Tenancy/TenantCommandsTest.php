<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Command\TenantMoveCommand;
use Nowo\ApiStudioBundle\Command\TenantOrphansCommand;
use Nowo\ApiStudioBundle\Command\TenantUnsuffixSlugsCommand;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceMovedEvent;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tests\Support\TenantOrmTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Tenant maintenance commands: orphans (read-only), move (slug check, dry run, event), unsuffix-slugs (dry run).
 */
final class TenantCommandsTest extends TestCase
{
    use TenantOrmTrait;

    public function testOrphans(): void
    {
        $em = $this->createTenantEntityManager();
        $this->tenantWorkspace($em, 'acme', 'owned');
        $command = new TenantOrphansCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every workspace belongs to a tenant.', $tester->getDisplay());

        $orphan = $this->tenantWorkspace($em, '', 'lost');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('lost', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        self::assertSame([['id' => $orphan->getId(), 'slug' => 'lost', 'name' => 'Lost']], json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMove(): void
    {
        $em   = $this->createTenantEntityManager();
        $lost = $this->tenantWorkspace($em, '', 'payments');
        $this->tenantWorkspace($em, 'beta', 'payments');
        $events     = new EventDispatcher();
        $dispatched = [];
        $events->addListener(ApiWorkspaceMovedEvent::class, static function (ApiWorkspaceMovedEvent $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });
        $tester = new CommandTester(new TenantMoveCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true), $events));
        $id     = (string) $lost->getId();

        self::assertSame(Command::FAILURE, $tester->execute(['workspace' => $id, '--to-type' => 'org', '--to-id' => 'beta']), 'slug taken at the destination');

        self::assertSame(Command::SUCCESS, $tester->execute(['workspace' => $id, '--to-type' => 'org', '--to-id' => 'acme', '--dry-run' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $em->clear();
        self::assertTrue($this->find($em, (int) $id)->getTenant()->isInstance());

        self::assertSame(Command::SUCCESS, $tester->execute(['workspace' => $id, '--to-type' => 'org', '--to-id' => 'acme']));
        $em->clear();
        self::assertSame('org:acme', $this->find($em, (int) $id)->getTenant()->key());
        self::assertCount(1, $dispatched);
        self::assertSame('', $dispatched[0]->from->key());

        self::assertSame(Command::SUCCESS, $tester->execute(['workspace' => $id, '--to-type' => 'org', '--to-id' => 'acme']));
        self::assertStringContainsString('already belongs', $tester->getDisplay());

        try {
            $tester->execute(['workspace' => '999']);
            self::fail('unknown workspace');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $tester->execute(['workspace' => 'abc']);
    }

    public function testUnsuffixSlugs(): void
    {
        $em = $this->createTenantEntityManager();
        foreach (['payments-2', 'orders', 'orders-3', 'billing-2', 'billing-3'] as $slug) {
            $this->tenantWorkspace($em, 'acme', $slug);
        }
        $this->tenantWorkspace($em, 'beta', 'payments-2');
        $tester = new CommandTester(new TenantUnsuffixSlugsCommand($this->tenantRegistry($em), new ApiStudioTenantContext(null, true)));
        $args   = ['--tenant-type' => 'org', '--tenant-id' => 'acme'];

        self::assertSame(Command::SUCCESS, $tester->execute($args + ['--dry-run' => true]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        $em->clear();
        self::assertSame(1, $this->countSlug($em, 'payments-2', 'acme'));

        self::assertSame(Command::SUCCESS, $tester->execute($args));
        $em->clear();
        $slugs = [];
        foreach ($em->createQueryBuilder()->select('w.slug')->from(ApiWorkspace::class, 'w')->where("w.tenantId = 'acme'")->orderBy('w.id')->getQuery()->getSingleColumnResult() as $slug) {
            $slugs[] = $slug;
        }
        // payments-2 → payments; orders-3 stays (orders taken); billing-2 → billing, billing-3 stays (now taken).
        self::assertSame(['payments', 'orders', 'orders-3', 'billing', 'billing-3'], $slugs);
        self::assertSame(1, $this->countSlug($em, 'payments-2', 'beta'), 'other tenants untouched');

        self::assertSame(Command::SUCCESS, $tester->execute($args));
        self::assertStringContainsString('No suffixed slug', $tester->getDisplay());
    }

    public function testCommandsNeedTenancy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenancy.enabled is false');

        (new CommandTester(new TenantOrphansCommand($this->createStub(ManagerRegistry::class), new ApiStudioTenantContext())))->execute([]);
    }

    public function testCommandsNeedAnEntityManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(RuntimeException::class);
        (new CommandTester(new TenantOrphansCommand($registry, new ApiStudioTenantContext(null, true))))->execute([]);
    }

    private function countSlug(EntityManager $em, string $slug, string $tenant): int
    {
        return (int) $em->createQueryBuilder()->select('COUNT(w.id)')->from(ApiWorkspace::class, 'w')
            ->where('w.slug = :slug AND w.tenantId = :tenant')->setParameter('slug', $slug)->setParameter('tenant', $tenant)
            ->getQuery()->getSingleScalarResult();
    }

    private function find(EntityManager $em, int $id): ApiWorkspace
    {
        $workspace = $em->find(ApiWorkspace::class, $id);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);

        return $workspace;
    }
}
