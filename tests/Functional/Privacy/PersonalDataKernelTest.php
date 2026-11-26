<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitEraser;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitExportProvider;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Tests\Functional\App\EntityManagerTestKernel;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Service\ResetInterface;

use function array_column;
use function dirname;
use function getmypid;
use function is_file;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * 1.24 GDPR and retention through a real kernel, on a standard host (default manager) and a DDD host (dedicated Api
 * Studio manager on its own connection): the UserKit eraser and export section reach the bundle's tables only, the
 * purge command is registered, and alternating subjects never see each other's rows.
 */
final class PersonalDataKernelTest extends TestCase
{
    private ?EntityManagerTestKernel $kernel = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hosts(): iterable
    {
        yield 'standard host' => [false];
        yield 'DDD host, dedicated manager' => [true];
    }

    #[DataProvider('hosts')]
    public function testTheUserKitBridgesEraseAndExportTheSubjectsRowsOnly(bool $dedicated): void
    {
        $studio               = $this->bootHost($dedicated);
        [$service, $endpoint] = $this->seed($studio);
        $this->history($studio, $endpoint, 'u:ana@example.test', 'https://api.example.test/a?token=REDACTED-TEST-tok');
        $this->history($studio, $endpoint, 'u:bob@example.test', 'https://api.example.test/b');
        $this->folder($studio, $service, 'u:ana@example.test');
        $connection = $studio->getConnection();

        $section = $this->exportProvider()->export(new InMemoryUser('ana@example.test', null));
        self::assertSame(['https://api.example.test/a?token=[REDACTED]'], array_column($section['history'], 'url'));
        self::assertSame(['Invoices'], array_column($section['folders_created'], 'name'));

        // UserKit ran the host identity eraser first: the user's identifier is already anonymised.
        $this->eraser()->erase(new ErasureRequest(new InMemoryUser('erased-1', null), null, [], 'ana@example.test'));

        self::assertSame(['u:bob@example.test'], $connection->fetchFirstColumn('SELECT executed_by FROM api_studio_request_history'));
        self::assertSame([null], $connection->fetchFirstColumn('SELECT created_by FROM api_studio_folder'));
        self::assertSame(['history' => [], 'folders_created' => []], $this->exportProvider()->export(new InMemoryUser('ana@example.test', null)));
        if ($dedicated) {
            $host = $this->registry()->getManager('default');
            self::assertInstanceOf(EntityManagerInterface::class, $host);
            self::assertFalse($host->getConnection()->createSchemaManager()->tablesExist(['api_studio_request_history']), 'the host database is never touched');
        }
    }

    #[DataProvider('hosts')]
    public function testThePurgeCommandRunsOnTheBundlesManager(bool $dedicated): void
    {
        $studio       = $this->bootHost($dedicated);
        [, $endpoint] = $this->seed($studio);
        $old          = $this->history($studio, $endpoint, 'u:ana@example.test', 'https://api.example.test/old');
        $this->history($studio, $endpoint, 'u:ana@example.test', 'https://api.example.test/new');
        $studio->getConnection()->executeStatement("UPDATE api_studio_request_history SET executed_at = '2001-01-01 00:00:00' WHERE id = ?", [$old]);

        $application = new Application($this->kernel());
        $tester      = new CommandTester($application->find('nowo:api-studio:history:purge'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 1 history row(s)', $tester->getDisplay());
        self::assertSame(['https://api.example.test/new'], $studio->getConnection()->fetchFirstColumn('SELECT request_url FROM api_studio_request_history'));
        self::assertInstanceOf(ApiStudioHistoryRetentionInterface::class, $this->container()->get(ApiStudioHistoryRetentionInterface::class));
    }

    /**
     * The same services serve many subjects in one kernel (memory: WorkerPersonalDataTest, built as production).
     */
    public function testOneKernelServesManySubjectsWithoutMixingThem(): void
    {
        $studio               = $this->bootHost(true);
        [$service, $endpoint] = $this->seed($studio);
        $this->history($studio, $endpoint, 'u:ana@example.test', 'https://api.example.test/ana');
        $this->history($studio, $endpoint, 'u:bob@example.test', 'https://api.example.test/bob');
        $this->folder($studio, $service, 'u:bob@example.test');
        $provider = $this->exportProvider();
        $users    = ['ana' => new InMemoryUser('ana@example.test', null), 'bob' => new InMemoryUser('bob@example.test', null)];

        for ($round = 0; $round < 100; ++$round) {
            $name = $round % 2 === 0 ? 'ana' : 'bob';
            $json = json_encode($provider->export($users[$name]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('/' . $name, $json);
            self::assertStringNotContainsString('/' . ($name === 'ana' ? 'bob' : 'ana'), $json);
            // Debug-only collectors (logger records, Doctrine query log, stopwatch) grow by design in a debug kernel.
            $this->resetDebugCollectors();
        }

        // A missing actor id erases nothing and leaves the other subject alone.
        $port = $this->container()->get(ApiStudioPersonalDataEraserInterface::class);
        self::assertInstanceOf(ApiStudioPersonalDataEraserInterface::class, $port);
        self::assertSame(0, $port->erase('u:nobody@example.test')->historyRowsDeleted);
        self::assertSame(2, (int) $studio->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_request_history'));
    }

    private function resetDebugCollectors(): void
    {
        $container = $this->kernel()->getContainer();
        foreach (EntityManagerTestKernel::DEBUG_COLLECTORS as $id) {
            $service = $container->has($id) ? $container->get($id) : null;
            if ($service instanceof DebugLoggerInterface) {
                $service->clear();
            }
            if ($service instanceof ResetInterface) {
                $service->reset();
            }
        }
    }

    private function bootHost(bool $dedicated): EntityManagerInterface
    {
        $bundle = ['history' => ['scope' => 'user'], 'folders' => ['enabled' => true]];
        if ($dedicated) {
            $bundle['entity_manager'] = 'api_studio';
        }
        $this->kernel = new EntityManagerTestKernel($dedicated ? $this->dedicatedDoctrine() : $this->standardDoctrine(), $bundle);
        $this->kernel->boot();

        foreach ($dedicated ? ['default', 'api_studio'] : ['default'] as $name) {
            $manager = $this->registry()->getManager($name);
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }
        $studio = $this->container()->get('nowo_api_studio.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $studio);

        return $studio;
    }

    /**
     * @return array{ApiService, ApiEndpoint}
     */
    private function seed(EntityManagerInterface $em): array
    {
        $workspace = new ApiWorkspace('Payments', 'payments');
        $service   = (new ApiService('Orders', 'orders'))->setWorkspace($workspace);
        $endpoint  = (new ApiEndpoint('List orders', 'list-orders'))->setService($service);
        $em->persist($workspace);
        $em->persist($service);
        $em->persist($endpoint);
        $em->flush();

        return [$service, $endpoint];
    }

    private function history(EntityManagerInterface $em, ApiEndpoint $endpoint, string $actor, string $url): int
    {
        $row = (new ApiRequestHistory())->setEndpoint($endpoint)->setExecutedBy($actor)->setRequestUrl($url)->setRequestMethod('GET')->setResponseStatus(200)->setResponseBody('{"secret":"REDACTED-TEST-body"}');
        $em->persist($row);
        $em->flush();

        return (int) $row->getId();
    }

    private function folder(EntityManagerInterface $em, ApiService $service, string $actor): void
    {
        $em->persist((new ApiFolder('Invoices'))->setService($service)->setCreatedBy($actor));
        $em->flush();
    }

    private function eraser(): ApiStudioUserKitEraser
    {
        $eraser = $this->container()->get(ApiStudioUserKitEraser::class);
        self::assertInstanceOf(ApiStudioUserKitEraser::class, $eraser);

        return $eraser;
    }

    private function exportProvider(): ApiStudioUserKitExportProvider
    {
        $provider = $this->container()->get(ApiStudioUserKitExportProvider::class);
        self::assertInstanceOf(ApiStudioUserKitExportProvider::class, $provider);

        return $provider;
    }

    private function kernel(): EntityManagerTestKernel
    {
        self::assertInstanceOf(EntityManagerTestKernel::class, $this->kernel);

        return $this->kernel;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel()->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    /**
     * @return array<string, mixed>
     */
    private function standardDoctrine(): array
    {
        return [
            'dbal' => ['url' => 'sqlite:///' . $this->database('gdpr_standard')],
            'orm'  => ['auto_mapping' => true, 'mappings' => self::hostMapping()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dedicatedDoctrine(): array
    {
        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'    => ['url' => 'sqlite:///' . $this->database('gdpr_host')],
                    'api_studio' => ['url' => 'sqlite:///' . $this->database('gdpr_studio')],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default'    => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => self::hostMapping()],
                    'api_studio' => ['connection' => 'api_studio'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function hostMapping(): array
    {
        return ['HostApp' => [
            'type'      => 'attribute',
            'is_bundle' => false,
            'dir'       => dirname(__DIR__) . '/App/Entity',
            'prefix'    => 'Nowo\\ApiStudioBundle\\Tests\\Functional\\App\\Entity',
        ]];
    }

    private function database(string $name): string
    {
        $file = sprintf('%s/nowo_api_studio_em_%s_%d.sqlite', sys_get_temp_dir(), $name, (int) getmypid());
        if (is_file($file)) {
            unlink($file);
        }
        $this->files[] = $file;

        return $file;
    }
}
