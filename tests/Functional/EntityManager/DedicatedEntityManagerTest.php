<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\EntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use Nowo\ApiStudioBundle\Tests\Functional\App\Entity\HostNote;
use Nowo\ApiStudioBundle\Tests\Functional\App\EntityManagerTestKernel;
use Nowo\ApiStudioBundle\Tests\Functional\App\EventRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Profiler\Profile;

use function array_keys;
use function dirname;
use function gc_collect_cycles;
use function getmypid;
use function is_file;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * `nowo_api_studio.entity_manager` (1.12, architecture bar rule 6) through a real kernel: a standard host keeps the
 * 1.x behaviour (default manager), a DDD host maps the entities on a dedicated manager only — also with
 * `auto_mapping: true` on the default one and without `default_entity_manager` — and Api Studio never flushes, clears
 * or resets the host's manager; in a kept-alive kernel (no services resetter) only the bundle's manager is recovered.
 */
final class DedicatedEntityManagerTest extends TestCase
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

    public function testStandardHostKeepsTheDefaultManager(): void
    {
        $this->boot($this->standardDoctrine());
        $registry = $this->registry();
        $default  = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $default);

        self::assertSame(['default'], array_keys((array) $this->container()->getParameter('doctrine.entity_managers')));
        self::assertSame($default, $registry->getManagerForClass(ApiWorkspace::class));
        self::assertSame($default, $this->container()->get('nowo_api_studio.entity_manager'));
        self::assertSame(TenantSqlFilter::class, $default->getConfiguration()->getFilterClassName(TenantSqlFilter::NAME));
    }

    public function testDedicatedManagerMapsTheEntitiesThereOnlyAndNeverFlushesTheHostManager(): void
    {
        $this->boot($this->dedicatedDoctrine(true), ['entity_manager' => 'api_studio']);
        [$host, $studio] = $this->managersWithSchema();

        self::assertSame('default', $this->container()->getParameter('doctrine.default_entity_manager'));
        self::assertTrue($host->getMetadataFactory()->isTransient(ApiWorkspace::class), 'not mapped on the auto-mapped host manager');
        self::assertSame($studio, $this->registry()->getManagerForClass(ApiWorkspace::class));
        self::assertSame($studio, $this->container()->get('nowo_api_studio.entity_manager'));
        self::assertSame(TenantSqlFilter::class, $studio->getConfiguration()->getFilterClassName(TenantSqlFilter::NAME));
        self::assertNull($host->getConfiguration()->getFilterClassName(TenantSqlFilter::NAME));

        // A host change pending in the same request (persisted, not flushed) must not be written by Api Studio.
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function () use ($host): void {
            $host->persist(new HostNote('pending host change'));
        });

        $client = new KernelBrowser($this->kernel());
        $client->disableReboot();
        $crawler = $client->request('GET', '/api-studio/workspaces/new');
        $client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Payments',
            'api_workspace_form[slug]' => 'payments',
        ]));
        self::assertSame(302, $client->getResponse()->getStatusCode());

        self::assertSame(1, (int) $studio->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_workspace'));
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host manager is never flushed');
        self::assertFalse($host->getConnection()->createSchemaManager()->tablesExist(['api_studio_workspace']), 'no Api Studio table on the host database');
    }

    public function testEventsCarryIdsAndFireAfterTheCommit(): void
    {
        $this->boot($this->dedicatedDoctrine(true), ['entity_manager' => 'api_studio']);
        [, $studio] = $this->managersWithSchema();
        $client     = new KernelBrowser($this->kernel());
        $client->disableReboot();

        $crawler = $client->request('GET', '/api-studio/workspaces/new');
        $client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Payments',
            'api_workspace_form[slug]' => 'payments',
        ]));
        $workspace = $studio->getRepository(ApiWorkspace::class)->findOneBy(['slug' => 'payments']);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        $workspaceId = (int) $workspace->getId();

        $recorder = $this->container()->get(EventRecorder::class);
        self::assertInstanceOf(EventRecorder::class, $recorder);
        [$created] = $recorder->of(ApiWorkspaceCreatedEvent::class);
        self::assertTrue($created['inTransaction'], 'the created event stays inside the transaction (atomic host writes)');
        self::assertSame($workspaceId, $created['event']->workspaceId);
        self::assertSame('payments', $created['event']->slug);
        [$committed] = $recorder->of(ApiWorkspaceCreatedCommittedEvent::class);
        self::assertFalse($committed['inTransaction'], 'the committed event fires after the commit');
        self::assertSame($workspaceId, $committed['event']->workspaceId);
        self::assertSame('payments', $committed['event']->slug);

        $crawler = $client->request('GET', sprintf('/api-studio/workspaces/%d/services/new', $workspaceId));
        $client->submit($crawler->filter('form[name="api_service_form"]')->form([
            'api_service_form[name]'    => 'Orders API',
            'api_service_form[slug]'    => 'orders',
            'api_service_form[baseUrl]' => 'https://93.184.215.14',
        ]));
        $service = $studio->getRepository(ApiService::class)->findOneBy(['slug' => 'orders']);
        self::assertInstanceOf(ApiService::class, $service);
        [$changed] = $recorder->of(ApiStudioResourceChangedEvent::class);
        self::assertFalse($changed['inTransaction']);
        self::assertSame(ApiStudioResourceChangedEvent::TYPE_SERVICE, $changed['event']->resourceType);
        self::assertSame((int) $service->getId(), $changed['event']->resourceId);
        self::assertSame($workspaceId, $changed['event']->workspaceId);
        self::assertSame(ApiStudioResourceChangedEvent::ACTION_CREATED, $changed['event']->action);

        $endpoint = (new ApiEndpoint('Ping', 'ping'))->setMethod(HttpMethod::Get)->setPath('/ping?token=secret-value')->setService($service);
        $studio->persist($endpoint);
        $studio->flush();
        $endpointId = (int) $endpoint->getId();
        $studio->clear();

        $crawler = $client->request('GET', sprintf('/api-studio/workspaces/%d/services/%d/endpoints/%d', $workspaceId, (int) $service->getId(), $endpointId));
        $token   = (string) $crawler->filter('#api-studio-tester')->attr('data-csrf-token');
        $client->request('POST', sprintf('/api-studio/execute/%d', $endpointId), [], [], ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], (string) json_encode([]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(201, $data['response_status']);

        [$executed] = $recorder->of(ApiRequestExecutedEvent::class);
        $event      = $executed['event'];
        self::assertFalse($executed['inTransaction']);
        self::assertSame($endpointId, $event->endpointId);
        self::assertSame((int) $service->getId(), $event->serviceId);
        self::assertSame($workspaceId, $event->workspaceId);
        self::assertNull($event->environmentId);
        self::assertNotNull($event->historyId);
        self::assertSame('GET', $event->method);
        self::assertSame('93.184.215.14', $event->host);
        self::assertSame(201, $event->statusCode);
        self::assertTrue($event->success);
        self::assertStringNotContainsString('secret-value', (string) json_encode((array) $event), 'no path, query or values on the event');
    }

    public function testHostWithoutADefaultManagerNameKeepsItsOwnDefault(): void
    {
        $doctrine = $this->dedicatedDoctrine(true, 'main');
        unset($doctrine['orm']['default_entity_manager']);
        $this->boot($doctrine, ['entity_manager' => 'api_studio']);

        self::assertSame(['main', 'api_studio'], array_keys((array) $this->container()->getParameter('doctrine.entity_managers')), 'no phantom "default" manager');
        self::assertSame('main', $this->container()->getParameter('doctrine.default_entity_manager'), 'the bundle manager never becomes the default');
        $main = $this->registry()->getManager('main');
        self::assertInstanceOf(EntityManagerInterface::class, $main);
        self::assertTrue($main->getMetadataFactory()->isTransient(ApiWorkspace::class));
        self::assertSame($this->registry()->getManager('api_studio'), $this->registry()->getManagerForClass(ApiWorkspace::class));
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_api_studio.entity_manager is "reports"');

        $this->boot($this->dedicatedDoctrine(false), ['entity_manager' => 'reports']);
    }

    /**
     * Kept-alive kernel without the services resetter: requests go through `http_kernel` handle/terminate. A closed
     * Api Studio manager is recovered on the next request; the host manager keeps its managed entities (never reset,
     * never cleared), and memory stays flat over many requests.
     */
    public function testWorkerRecoversOnlyTheBundleManager(): void
    {
        $this->boot($this->dedicatedDoctrine(true), ['entity_manager' => 'api_studio']);
        [$host, $studio] = $this->managersWithSchema();
        $note            = new HostNote('managed by the host');
        $host->persist($note);
        $host->flush();
        $studio->persist(new ApiWorkspace('Payments', 'payments'));
        $studio->flush();
        $studio->clear();

        $httpKernel = $this->container()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        self::assertInstanceOf(TerminableInterface::class, $httpKernel);

        $studio->close();
        self::assertSame(200, $this->handle($httpKernel, '/api-studio/workspaces'));
        self::assertTrue($studio->isOpen(), 'the bundle manager is recovered in place');
        self::assertTrue($host->contains($note), 'the host manager is neither reset nor cleared');

        $this->handle($httpKernel, '/api-studio/workspaces');
        $this->resetDebugCollectors();
        gc_collect_cycles();
        $baseline = memory_get_usage();
        for ($i = 0; $i < 300; ++$i) {
            self::assertSame(200, $this->handle($httpKernel, '/api-studio/workspaces'));
            $this->resetDebugCollectors();
        }
        gc_collect_cycles();
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat in a kept-alive kernel');
        self::assertTrue($host->contains($note));
    }

    /**
     * The test kernel runs in debug mode (allow_unauthenticated): the traceable dispatcher, the stopwatch, the debug
     * logger, the DBAL query log and the Twig profile record every request until the services resetter runs. They do not exist in a
     * production worker; only they are reset.
     */
    private function resetDebugCollectors(): void
    {
        $container = $this->kernel()->getContainer();
        foreach (EntityManagerTestKernel::DEBUG_COLLECTORS as $id) {
            $service = $container->has($id) ? $container->get($id) : null;
            if ($service instanceof DebugLoggerInterface) {
                $service->clear();
            }
            if ($service instanceof ResetInterface || $service instanceof Profile) {
                $service->reset();
            }
        }
    }

    private function handle(HttpKernelInterface&TerminableInterface $httpKernel, string $uri): int
    {
        $request  = Request::create($uri);
        $response = $httpKernel->handle($request);
        $httpKernel->terminate($request, $response);

        return $response->getStatusCode();
    }

    /**
     * @return array{EntityManagerInterface, EntityManagerInterface}
     */
    private function managersWithSchema(): array
    {
        $host   = $this->registry()->getManager('default');
        $studio = $this->registry()->getManager('api_studio');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $studio);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($studio))->createSchema($studio->getMetadataFactory()->getAllMetadata());

        return [$host, $studio];
    }

    /**
     * @param array<string, mixed> $doctrine
     * @param array<string, mixed> $bundle
     */
    private function boot(array $doctrine, array $bundle = []): void
    {
        $this->kernel = new EntityManagerTestKernel($doctrine, $bundle);
        $this->kernel->boot();
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
            'dbal' => ['url' => 'sqlite:///' . $this->database('standard')],
            'orm'  => ['auto_mapping' => true, 'mappings' => self::hostMapping()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dedicatedDoctrine(bool $autoMapping, string $hostManager = 'default'): array
    {
        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'    => ['url' => 'sqlite:///' . $this->database('host')],
                    'api_studio' => ['url' => 'sqlite:///' . $this->database('studio')],
                ],
            ],
            'orm' => [
                'default_entity_manager' => $hostManager,
                'entity_managers'        => [
                    $hostManager => ['connection' => 'default', 'auto_mapping' => $autoMapping, 'mappings' => self::hostMapping()],
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
        // Same path for every test of the process (one compiled container per configuration); emptied per test.
        $file = sprintf('%s/nowo_api_studio_em_%s_%d.sqlite', sys_get_temp_dir(), $name, (int) getmypid());
        if (is_file($file)) {
            unlink($file);
        }
        $this->files[] = $file;

        return $file;
    }
}
