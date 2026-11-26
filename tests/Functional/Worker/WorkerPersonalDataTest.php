<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExporterInterface;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistory;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_column;
use function gc_collect_cycles;
use function memory_get_usage;
use function mkdir;

/**
 * 1.24 (REQ-RUNTIME-002): ONE kept-alive kernel built as production (DDD host, dedicated `api_studio` manager, no
 * services resetter). 500 rounds alternate two subjects: each round erases a fresh row of a third actor, exports
 * both subjects (each sees only its own rows), and every 50th round dispatches the purge message on the real
 * Messenger bus (the expired rows go once, later runs delete nothing). The Api Studio manager holds no entity
 * afterwards and memory stays within 2 MiB after a warm-up.
 */
final class WorkerPersonalDataTest extends TestCase
{
    private const ROUNDS = 500;

    private const WARM_UP = 50;

    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    private ?WorkerTestKernel $kernel = null;

    protected function setUp(): void
    {
        (new Filesystem())->remove([WorkerTestKernel::baseDir() . '/host.sqlite', WorkerTestKernel::baseDir() . '/studio.sqlite']);
        @mkdir(WorkerTestKernel::baseDir(), 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(WorkerTestKernel::baseDir());
    }

    public function testSubjectsAlternateWithoutLeaksAndThePurgeMessageIsIdempotent(): void
    {
        $studio     = $this->boot();
        $connection = $studio->getConnection();
        $endpointId = $this->seed($studio);
        foreach (['alice', 'bob'] as $user) {
            $this->insert($connection, $endpointId, 'u:' . $user, 'https://api.example.test/' . $user, '2026-11-25 10:00:00');
        }
        for ($i = 0; $i < 25; ++$i) {
            $this->insert($connection, $endpointId, 'u:alice', 'https://api.example.test/expired', '2001-01-01 00:00:00');
        }

        $container = $this->container();
        $eraser    = $container->get(ApiStudioPersonalDataEraserInterface::class);
        $exporter  = $container->get(ApiStudioPersonalDataExporterInterface::class);
        $bus       = $container->get('messenger.default_bus');
        self::assertInstanceOf(ApiStudioPersonalDataEraserInterface::class, $eraser);
        self::assertInstanceOf(ApiStudioPersonalDataExporterInterface::class, $exporter);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            if ($round % 50 === 0) {
                $bus->dispatch(new PurgeApiStudioHistory());
                self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM api_studio_request_history WHERE request_url LIKE '%expired'"), 'round ' . $round);
            }

            $this->insert($connection, $endpointId, 'u:carol', 'https://api.example.test/carol', '2026-11-25 10:00:00');
            self::assertSame(1, $eraser->erase('u:carol')->historyRowsDeleted, 'round ' . $round);

            $user  = $round % 2 === 0 ? 'alice' : 'bob';
            $other = $user === 'alice' ? 'bob' : 'alice';
            self::assertSame(['https://api.example.test/' . $user], array_column($exporter->export('u:' . $user)->history, 'url'), 'round ' . $round);
            self::assertSame([], $exporter->export('u:' . $other . 'x')->history);
            self::assertSame(0, $studio->getUnitOfWork()->size(), 'round ' . $round . ': no Api Studio entity is kept');

            if ($round === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM api_studio_request_history'));
        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    private function boot(): EntityManagerInterface
    {
        $this->kernel = new WorkerTestKernel();
        $this->kernel->boot();
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $studio = null;
        foreach (['default', 'api_studio'] as $name) {
            $manager = $registry->getManager($name);
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
            $studio = $manager;
        }

        return $studio;
    }

    private function seed(EntityManagerInterface $studio): int
    {
        $workspace = (new ApiWorkspace('Acme', 'acme'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $service   = (new ApiService('Orders', 'orders'))->setWorkspace($workspace);
        $endpoint  = (new ApiEndpoint('List orders', 'list-orders'))->setService($service);
        $studio->persist($workspace);
        $studio->persist($service);
        $studio->persist($endpoint);
        $studio->flush();
        $id = (int) $endpoint->getId();
        $studio->clear();

        return $id;
    }

    private function insert(Connection $connection, int $endpointId, string $actor, string $url, string $executedAt): void
    {
        $connection->insert('api_studio_request_history', [
            'endpoint_id'      => $endpointId,
            'executed_at'      => $executedAt,
            'executed_by'      => $actor,
            'request_url'      => $url,
            'request_method'   => 'GET',
            'request_headers'  => '[]',
            'request_body'     => 'REDACTED-TEST-body',
            'response_status'  => 200,
            'response_headers' => '[]',
            'success'          => 1,
        ]);
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(WorkerTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
