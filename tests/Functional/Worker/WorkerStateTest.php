<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\EntityManagerBoundarySubscriber;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\App\Entity\HostNote;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

use function array_filter;
use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function mkdir;

/**
 * 1.12.1 (REQ-RUNTIME-002 rule 4): ONE kept-alive kernel (built as production, a DDD host with the dedicated
 * `api_studio` manager), the services resetter never called: `http_kernel` driven directly and a Messenger
 * {@see Worker} run without {@see ResetServicesListener} (`messenger:consume --no-reset`).
 *
 * Two tenants (acme with alice and bob, globex with carol and dave) alternate over 500 iterations: the workspace list of
 * each tenant as each of its users (2,000 requests), a message that reads and flushes one tenant's workspace and a
 * message that renames the other's in memory and fails before its flush (1,000 messages). Another worker renames the
 * workspace through DBAL before every request. Each page and message shows its own tenant's current name and nothing
 * of the other tenant, the change of a failed message is never written by a later flush, the Api Studio manager holds
 * no entity after any unit of work, the host's manager keeps its managed entity (never cleared by the bundle), and
 * memory stays within 4 MiB after a 50-iteration warm-up.
 */
#[CoversClass(EntityManagerBoundarySubscriber::class)]
final class WorkerStateTest extends TestCase
{
    private const ITERATIONS = 500;

    private const WARM_UP = 50;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const TENANTS = ['acme' => ['alice', 'bob'], 'globex' => ['carol', 'dave']];

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

    public function testTwoTenantsAlternateOverHttpAndMessengerWithoutTheResetter(): void
    {
        [$host, $studio] = $this->boot();
        $note            = new HostNote('managed by the host');
        $host->persist($note);
        $host->flush();
        $noteId = (int) $note->getId();
        $ids    = [];
        foreach (self::TENANTS as $org => $users) {
            $workspace = (new ApiWorkspace($org . ' workspace', $org))->assignTenant(new ApiStudioTenant('org', $org));
            $studio->persist($workspace);
            $studio->flush();
            $ids[$org] = (int) $workspace->getId();
        }
        $studio->clear();

        $container  = $this->container();
        $http       = $container->get('http_kernel');
        $bus        = $container->get('messenger.default_bus');
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $reset = array_filter($dispatcher->getListeners(WorkerRunningEvent::class), static fn (mixed $listener): bool => is_array($listener) && $listener[0] instanceof ResetServicesListener);
        self::assertSame([], $reset, 'the worker runs without ResetServicesListener (--no-reset)');
        // One message per run: stop as soon as the worker reports it handled (or found nothing).
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            $event->getWorker()->stop();
        });
        $transport = new InMemoryTransport();
        $handler   = $container->get(WorkerProbeHandler::class);
        self::assertInstanceOf(WorkerProbeHandler::class, $handler);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            // DoctrineBundle's Messenger subscriber clears every manager after a message; the host loads its row again.
            $note = $host->find(HostNote::class, $noteId);
            self::assertInstanceOf(HostNote::class, $note);
            foreach (self::TENANTS as $org => $users) {
                $other = $org === 'acme' ? 'globex' : 'acme';
                foreach ($users as $user) {
                    // Another worker renames the workspace before each request.
                    $studio->getConnection()->executeStatement('UPDATE api_studio_workspace SET name = ? WHERE id = ?', [$this->workspaceName($org, $i, $user), $ids[$org]]);
                    $content = $this->page($http, '/orgs/' . $org . '/api-studio/workspaces', $user, $i);
                    self::assertStringContainsString($this->workspaceName($org, $i, $user), $content, 'iteration ' . $i . ': ' . $user . ' sees the current name of the ' . $org . ' workspace');
                    self::assertStringNotContainsString($other, $content, 'iteration ' . $i . ': ' . $user . ' sees nothing of the other tenant');
                    self::assertSame(0, $studio->getUnitOfWork()->size(), 'iteration ' . $i . ': no Api Studio entity survives the request');
                }
            }
            self::assertTrue($host->contains($note), 'iteration ' . $i . ': the host manager is never cleared by Api Studio');

            $reads = $i % 2 === 0 ? 'acme' : 'globex';
            $fails = $i % 2 === 0 ? 'globex' : 'acme';
            $this->consume($transport, $bus, $dispatcher, new WorkerProbeMessage($ids[$reads], false));
            self::assertSame(0, $studio->getUnitOfWork()->size(), 'iteration ' . $i . ': no Api Studio entity survives the message');
            $this->consume($transport, $bus, $dispatcher, new WorkerProbeMessage($ids[$fails], true, 'dirty ' . $i));
            self::assertSame(0, $studio->getUnitOfWork()->size(), 'iteration ' . $i . ': no Api Studio entity survives the failed message');

            self::assertSame([$this->workspaceName($reads, $i, self::TENANTS[$reads][1]), $this->workspaceName($fails, $i, self::TENANTS[$fails][1])], $handler->seen, 'iteration ' . $i . ': each message reads the current row');
            foreach ($ids as $org => $id) {
                self::assertSame($this->workspaceName($org, $i, self::TENANTS[$org][1]), $studio->getConnection()->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$id]), 'iteration ' . $i . ': a failed message change is never flushed later');
            }

            $handler->seen = [];
            $transport->reset();
            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    public function testAClosedManagerIsRecreatedAtTheNextBoundary(): void
    {
        [, $studio] = $this->boot();
        $studio->close();

        $subscriber = $this->container()->get(EntityManagerBoundarySubscriber::class);
        self::assertInstanceOf(EntityManagerBoundarySubscriber::class, $subscriber);
        $subscriber->onMessageReceived(new stdClass());

        self::assertTrue($this->studio()->isOpen(), 'the bundle manager is recreated');
    }

    public function testNestedCommandKeepsTheOuterUnitOfWorkAndTheOutermostEndClearsIt(): void
    {
        [, $studio] = $this->boot();
        $workspace  = (new ApiWorkspace('acme workspace', 'acme'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $studio->persist($workspace);
        $studio->flush();
        $id = (int) $workspace->getId();
        $studio->clear();

        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        /** @var ArrayObject<string, bool> $seen */
        $seen = new ArrayObject();
        $application->addCommand(new class extends Command {
            public function __construct()
            {
                parent::__construct('app:inner');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        });
        $application->addCommand(new class($studio, $application, $id, $seen) extends Command {
            /** @param ArrayObject<string, bool> $seen */
            public function __construct(private readonly EntityManagerInterface $studio, private readonly Application $application, private readonly int $id, private readonly ArrayObject $seen)
            {
                parent::__construct('app:outer');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $workspace = $this->studio->find(ApiWorkspace::class, $this->id);
                if (!$workspace instanceof ApiWorkspace) {
                    return Command::FAILURE;
                }
                $workspace->setName('renamed by the outer command');
                // In-process nested command (Application::doRun()): its terminate must not clear the outer unit of work.
                $this->application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
                $this->seen['managed after the nested command'] = $this->studio->contains($workspace);
                $this->studio->flush();

                return Command::SUCCESS;
            }
        });

        $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput());

        self::assertTrue($seen['managed after the nested command'] ?? false, 'the nested command keeps the outer managed entity and its pending change');
        self::assertSame('renamed by the outer command', $studio->getConnection()->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$id]));
        self::assertSame(0, $studio->getUnitOfWork()->size(), 'the end of the outermost command clears the manager');
    }

    public function testMainRequestHandledInsideAMessageKeepsTheMessageUnitOfWork(): void
    {
        [, $studio] = $this->boot();
        $workspace  = (new ApiWorkspace('acme workspace', 'acme'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $studio->persist($workspace);
        $studio->flush();
        $id = (int) $workspace->getId();
        $studio->clear();
        $http       = $this->container()->get('http_kernel');
        $dispatcher = $this->container()->get('event_dispatcher');
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $envelope = new Envelope(new WorkerProbeMessage($id, false));

        // A message handler edits a workspace, renders a page in-process (e.g. a PDF), then flushes.
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'probe'));
        $loaded = $studio->find(ApiWorkspace::class, $id);
        self::assertInstanceOf(ApiWorkspace::class, $loaded);
        $loaded->setName('renamed by the message');
        $this->page($http, '/orgs/acme/api-studio/workspaces', 'alice', 0);
        self::assertTrue($studio->contains($loaded), 'the in-process request keeps the message unit of work');
        $studio->flush();
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'probe'));

        self::assertSame('renamed by the message', $studio->getConnection()->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$id]));
        self::assertSame(0, $studio->getUnitOfWork()->size(), 'the end of the message clears the manager');
    }

    public function testServicesResetterStillResetsTheBundleManager(): void
    {
        [, $studio] = $this->boot();
        $workspace  = (new ApiWorkspace('acme workspace', 'acme'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $studio->persist($workspace);
        $studio->flush();
        self::assertSame(1, $studio->getUnitOfWork()->size());

        $resetter = $this->container()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame(0, $this->studio()->getUnitOfWork()->size());
    }

    private function workspaceName(string $org, int $iteration, string $user): string
    {
        return $org . ' workspace ' . $iteration . ' for ' . $user;
    }

    private function page(HttpKernel $http, string $uri, string $user, int $iteration): string
    {
        $request = Request::create($uri);
        $request->headers->set('X-Test-User', $user);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode(), 'iteration ' . $iteration . ': ' . $uri . ' as ' . $user);
        $http->terminate($request, $response);

        return (string) $response->getContent();
    }

    private function consume(InMemoryTransport $transport, MessageBusInterface $bus, EventDispatcherInterface $dispatcher, WorkerProbeMessage $message): void
    {
        $transport->send(new Envelope($message));
        (new Worker(['probe' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }

    /**
     * @return array{EntityManagerInterface, EntityManagerInterface} the host manager and the Api Studio manager
     */
    private function boot(): array
    {
        $this->kernel = new WorkerTestKernel();
        $this->kernel->boot();
        $host   = $this->registry()->getManager('default');
        $studio = $this->studio();
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($studio))->createSchema($studio->getMetadataFactory()->getAllMetadata());

        return [$host, $studio];
    }

    private function studio(): EntityManagerInterface
    {
        $studio = $this->registry()->getManager('api_studio');
        self::assertInstanceOf(EntityManagerInterface::class, $studio);

        return $studio;
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(WorkerTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * Memory after a cycle collection: request and envelope objects form reference cycles that PHP frees only when the
     * collector runs, so the raw figure depends on GC timing, not on retained state.
     */
    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
