<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\EventListener\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * 1.12.1 (REQ-RUNTIME-002 rule 4): the boundaries of {@see EntityManagerBoundarySubscriber} on the bundle-owned
 * manager, without the services resetter.
 */
final class EntityManagerBoundarySubscriberTest extends TestCase
{
    private EntityManagerInterface $manager;

    private bool $open = true;

    private int $clears = 0;

    private ManagerRegistry&MockObject $registry;

    private EventDispatcher $dispatcher;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(EntityManagerInterface::class);
        $this->manager->method('isOpen')->willReturnCallback(fn (): bool => $this->open);
        $this->manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $this->registry   = $this->createMock(ManagerRegistry::class);
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new EntityManagerBoundarySubscriber($this->registry, [$this->manager], 'api_studio'));
        $this->kernel = $this->createStub(HttpKernelInterface::class);
    }

    public function testSubscribesToTheRequestMessengerAndConsoleBoundaries(): void
    {
        $events = EntityManagerBoundarySubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onKernelFinishRequest', -4096], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame(['clear', -4096], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onConsoleCommand', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleTerminate', -4096], $events[ConsoleEvents::TERMINATE]);
    }

    public function testMainRequestStartAndTerminateClearTheManager(): void
    {
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        self::assertSame(1, $this->clears);

        $this->dispatcher->dispatch(new TerminateEvent($this->kernel, new Request(), new Response()), KernelEvents::TERMINATE);
        self::assertSame(2, $this->clears);
    }

    public function testSubRequestKeepsTheMainRequestUnitOfWork(): void
    {
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->request(HttpKernelInterface::SUB_REQUEST);
        $this->finishRequest(HttpKernelInterface::SUB_REQUEST);

        self::assertSame(1, $this->clears, 'a fragment shares the main request unit of work');
    }

    public function testMessageBoundariesClearTheManager(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('failed')));

        self::assertSame(4, $this->clears);
    }

    public function testOutermostCommandClearsAtStartAndEnd(): void
    {
        $this->consoleCommand();
        $this->consoleTerminate();

        self::assertSame(2, $this->clears);
    }

    public function testNestedCommandDoesNotClearTheOuterUnitOfWork(): void
    {
        $this->consoleCommand();
        $this->consoleCommand(); // Application::doRun() inside the outer command
        $this->consoleTerminate();
        self::assertSame(1, $this->clears, 'only the start of the outer command');

        $this->consoleTerminate();
        self::assertSame(2, $this->clears);
    }

    public function testCommandRunFromAMessageHandlerOrAControllerDoesNotClear(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->consoleCommand();
        $this->consoleTerminate();
        self::assertSame(1, $this->clears, 'the message keeps its unit of work');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));

        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->consoleCommand();
        $this->consoleTerminate();
        self::assertSame(3, $this->clears, 'the request keeps its unit of work');

        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->consoleTerminate();
        self::assertSame(4, $this->clears, 'after the request a command end clears again');
    }

    public function testSkippedMessageDoesNotBlockTheClearAfterTheWorkerStops(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->consoleTerminate();

        self::assertSame(2, $this->clears);
    }

    public function testAClosedManagerIsRecreatedNotCleared(): void
    {
        $this->open = false;
        $this->registry->expects(self::once())->method('resetManager')->with('api_studio');

        $this->request(HttpKernelInterface::MAIN_REQUEST);

        self::assertSame(0, $this->clears);
    }

    public function testAClosedManagerWithoutANameIsLeftAlone(): void
    {
        $this->open = false;
        $registry   = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');

        (new EntityManagerBoundarySubscriber($registry, [$this->manager]))->clear();

        self::assertSame(0, $this->clears);
    }

    public function testBoundaryNeverBuildsAnUninitializedManager(): void
    {
        $container = new ContainerBuilder();
        $container->register('registry', ManagerRegistry::class)->setSynthetic(true);
        $container->register('studio_manager', stdClass::class)->setPublic(true);
        $container->register(EntityManagerBoundarySubscriber::class, EntityManagerBoundarySubscriber::class)
            ->setPublic(true)
            ->setArguments([
                new Reference('registry'),
                new IteratorArgument([new Reference('studio_manager', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
                'api_studio',
            ]);
        $container->compile();
        $container->set('registry', $this->registry);

        $subscriber = $container->get(EntityManagerBoundarySubscriber::class);
        self::assertInstanceOf(EntityManagerBoundarySubscriber::class, $subscriber);
        $subscriber->onMessageReceived();

        self::assertFalse($container->initialized('studio_manager'), 'the boundary does not build the manager');
    }

    private function request(int $type): void
    {
        $this->dispatcher->dispatch(new RequestEvent($this->kernel, new Request(), $type), KernelEvents::REQUEST);
    }

    private function finishRequest(int $type): void
    {
        $this->dispatcher->dispatch(new FinishRequestEvent($this->kernel, new Request(), $type), KernelEvents::FINISH_REQUEST);
    }

    private function consoleCommand(): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
    }

    private function consoleTerminate(): void
    {
        $this->dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:probe'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
    }
}
