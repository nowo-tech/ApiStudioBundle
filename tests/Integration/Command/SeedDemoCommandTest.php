<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Command\SeedDemoCommand;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\DemoSeedService;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The demo seed is idempotent on a real database and --fresh rebuilds it from scratch.
 */
final class SeedDemoCommandTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testSeedTwiceThenFresh(): void
    {
        $em         = $this->createEntityManager();
        $registry   = $this->createRegistry($em);
        $workspaces = new ApiWorkspaceRepository($registry);
        $tester     = new CommandTester(new SeedDemoCommand(new DemoSeedService($em, $workspaces, new ApiServiceRepository($registry))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Demo API Studio data seeded.', $tester->getDisplay());
        $counts = $this->counts($em);
        self::assertSame(1, $counts[ApiWorkspace::class]);
        self::assertGreaterThanOrEqual(5, $counts[ApiService::class]);
        self::assertGreaterThan(0, $counts[ApiRequestExample::class]);
        self::assertGreaterThan(0, $counts[ApiResponseExample::class]);
        self::assertGreaterThan(0, $counts[ApiEndpointTranslation::class]);

        // Re-seeding the same database (managed and reloaded entities) adds nothing.
        $tester->execute([]);
        self::assertSame($counts, $this->counts($em));
        $em->clear();
        $tester->execute([]);
        self::assertSame($counts, $this->counts($em));

        $em->clear();
        $before = $workspaces->findOneBy(['slug' => 'demo']);
        self::assertInstanceOf(ApiWorkspace::class, $before);
        $oldId = $before->getId();

        self::assertSame(Command::SUCCESS, $tester->execute(['--fresh' => true]));
        self::assertSame($counts, $this->counts($em));
        $after = $workspaces->findOneBy(['slug' => 'demo']);
        self::assertInstanceOf(ApiWorkspace::class, $after);
        self::assertNotSame($oldId, $after->getId());
    }

    /**
     * @return array<class-string, int>
     */
    private function counts(EntityManagerInterface $em): array
    {
        $counts = [];
        foreach ([ApiWorkspace::class, ApiService::class, ApiEndpoint::class, ApiEndpointTranslation::class, ApiRequestExample::class, ApiResponseExample::class] as $class) {
            $counts[$class] = (int) $em->createQuery('SELECT COUNT(e) FROM ' . $class . ' e')->getSingleScalarResult();
        }

        return $counts;
    }
}
