<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiStudioBundle\EventListener\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

use function array_pop;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

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

    private HttpKernel $http;

    private Application $application;

    /** @var list<array{0: Command, 1: ArrayInput}> */
    private array $runningCommands = [];

    /** @var list<Request> */
    private array $mainRequests = [];

    private ?Request $finishedMainRequest = null;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(EntityManagerInterface::class);
        $this->manager->method('isOpen')->willReturnCallback(fn (): bool => $this->open);
        $this->manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->kernel   = $this->createStub(HttpKernelInterface::class);
        $this->boot();
    }

    /**
     * One dispatcher shared by a real HttpKernel and a real console Application (no services resetter).
     *
     * @param (Closure(EventDispatcher): void)|null $before listeners registered before the subscriber
     */
    private function boot(?Closure $before = null): void
    {
        $this->dispatcher = new EventDispatcher();
        if ($before instanceof Closure) {
            $before($this->dispatcher);
        }
        $this->dispatcher->addSubscriber(new EntityManagerBoundarySubscriber([$this->registry], [$this->manager], 'api_studio'));
        $this->http        = new HttpKernel($this->dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());
        $this->application = new Application();
        $this->application->setAutoExit(false);
        $this->application->setCatchExceptions(false);
        $this->application->setDispatcher($this->dispatcher);
    }

    public function testSubscribesToTheRequestMessengerAndConsoleBoundaries(): void
    {
        $events = EntityManagerBoundarySubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['onKernelFinishRequest', -4096], $events[KernelEvents::FINISH_REQUEST]);
        self::assertSame(['onKernelTerminate', -4096], $events[KernelEvents::TERMINATE]);
        self::assertSame(['onMessageReceived', 4096], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageHandledEvent::class]);
        self::assertSame(['onMessageFinished', -4096], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onWorkerStopped', -4096], $events[WorkerMessageSkipEvent::class]);
        self::assertSame(['onConsoleCommand', PHP_INT_MAX], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['onConsoleError', PHP_INT_MAX], $events[ConsoleEvents::ERROR]);
        self::assertSame([['onConsoleEnd', PHP_INT_MAX], ['onConsoleTerminate', -4096]], $events[ConsoleEvents::TERMINATE]);
    }

    public function testMainRequestStartAndTerminateClearTheManager(): void
    {
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        self::assertSame(1, $this->clears);

        $this->terminate();
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
        $this->consoleCommand();
        $this->consoleTerminate();
        self::assertSame(5, $this->clears, 'after the request a command clears at its start and end again');
    }

    public function testMainRequestHandledInsideAMessageOrACommandDoesNotClear(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        // In-process http_kernel->handle() inside the handler (e.g. rendering a PDF).
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->terminate();
        self::assertSame(1, $this->clears, 'the message keeps its unit of work');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertSame(2, $this->clears);

        $this->consoleCommand();
        // In-process http_kernel->handle() inside the command (e.g. rendering an e-mail).
        $this->request(HttpKernelInterface::MAIN_REQUEST);
        $this->finishRequest(HttpKernelInterface::MAIN_REQUEST);
        $this->terminate();
        self::assertSame(3, $this->clears, 'the command keeps its unit of work');
        $this->consoleTerminate();
        self::assertSame(4, $this->clears);
    }

    public function testAClosedManagerWithoutABuiltRegistryIsLeftToTheRecoverySubscriber(): void
    {
        $this->open = false;

        (new EntityManagerBoundarySubscriber([], [$this->manager], 'api_studio'))->clear();

        self::assertSame(0, $this->clears);
    }

    public function testSkippedMessageDoesNotBlockTheClearAfterTheWorkerStops(): void
    {
        $this->consoleCommand(); // messenger:consume
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->consoleTerminate();

        self::assertSame(3, $this->clears);
    }

    public function testASkipEventEndsTheMessage(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageSkipEvent(new Envelope(new stdClass()), 'async'));
        self::assertSame(1, $this->clears);

        // Without a skip event, the next received message ends the previous one.
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(6, $this->clears);
    }

    /**
     * Audit NM (a): a `console.terminate` listener that throws no longer leaves the process "inside a command".
     */
    public function testATerminateListenerThatThrowsDoesNotStopLaterRequestClears(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::TERMINATE, static function (): void {
            throw new LogicException('terminate listener failed');
        });
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->runAndCatch('app:probe', 'terminate listener failed');
        self::assertSame(1, $this->clears, 'The listener stopped the end-of-command clear.');

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears, 'The next main request clears at its start and at terminate.');
    }

    /**
     * Audit NM (b): a `console.error` listener that throws (Symfony then skips `console.terminate`) no longer leaves
     * the process "inside a command".
     */
    public function testAnErrorListenerThatThrowsDoesNotStopLaterRequestClears(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): void {
            throw new LogicException('error listener failed');
        });
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'error listener failed');
        self::assertSame(1, $this->clears);

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears, 'The next main request clears at its start and at terminate.');
    }

    /**
     * A failing command whose error is handled still clears once at its end (`console.error` then `console.terminate`).
     */
    public function testAFailedOutermostCommandClearsAtItsEnd(): void
    {
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'command failed');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NM: when even the bookkeeping listener is skipped (a listener registered before it at PHP_INT_MAX throws),
     * the end of the enclosing request or message restores the console depth.
     */
    public function testTheEnclosingRequestAndMessageHealASkippedBookkeeping(): void
    {
        $this->boot(static function (EventDispatcher $dispatcher): void {
            $dispatcher->addListener(ConsoleEvents::ERROR, static function (): void {
                throw new LogicException('error listener failed');
            }, PHP_INT_MAX);
        });
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->handle(function (): Response {
            $this->runAndCatch('app:failing', 'error listener failed');

            return new Response();
        });
        self::assertSame(2, $this->clears, 'The request end restored the depth, so terminate cleared.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears);

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertSame(6, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(8, $this->clears, 'The message end restored the depth.');
    }

    /**
     * Audit NM (c): an unknown nested command (`console.error` without a command) or a stray terminate never ends the
     * outer unit.
     */
    public function testAnUnknownNestedCommandDoesNotDiscardTheOuterUnit(): void
    {
        $this->command('app:outer', function (): int {
            $input = new ArrayInput(['command' => 'app:missing']);
            $input->setInteractive(false);
            try {
                $this->application->doRun($input, new NullOutput());
            } catch (CommandNotFoundException) {
                // Command "app:missing" is not defined.
            }
            $this->dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:stray'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
            $this->handle(static fn (): Response => new Response());
            self::assertSame(1, $this->clears, 'The outer unit is still running.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:outer');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NM (d): a `console.command` listener above 4096 that stops the propagation in a nested command no longer
     * skips the nested start, so the nested end does not clear the outer unit mid-run.
     */
    public function testAStoppedNestedCommandStartDoesNotClearTheOuterUnit(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:inner') {
                $event->stopPropagation();
            }
        }, 5000);
        $this->command('app:inner', static fn (): int => Command::SUCCESS);
        $this->command('app:outer', function (): int {
            $this->application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
            self::assertSame(1, $this->clears, 'The nested end must not clear the outer unit.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:outer');
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit NL2-v: a command that runs itself in-process (same Command object, new input) whose inner run fails with a
     * throwing error listener keeps the outer run's unit of work, and the outer end still clears.
     */
    public function testASelfRecursiveCommandWithAThrowingErrorListenerKeepsTheOuterRun(): void
    {
        $throw = static function (): never {
            throw new LogicException('error listener failed');
        };
        $depth = 0;
        $this->command('app:rec', function () use (&$depth, $throw): int {
            ++$depth;
            if ($depth > 1) {
                throw new RuntimeException('inner failed');
            }
            $this->dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            $this->runAndCatch('app:rec', 'error listener failed');
            $this->dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
            self::assertSame(1, $this->clears, 'The inner run must not clear the outer run.');

            return Command::SUCCESS;
        });

        $this->runCommand('app:rec');
        self::assertSame(2, $this->clears, 'The outer end clears.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears);
    }

    /**
     * Audit NL2-v: an error listener at PHP_INT_MIN that throws leaves the frame marked; the next unit drops it.
     */
    public function testAnErrorListenerAtTheLowestPriorityThatThrowsHealsAtTheNextUnit(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new LogicException('error listener failed');
        }, PHP_INT_MIN);
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->runAndCatch('app:failing', 'error listener failed');
        self::assertSame(1, $this->clears);
        $this->runCommand('app:probe');
        self::assertSame(3, $this->clears, 'The next command is the outermost unit again.');

        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(8, $this->clears, 'The next message and request clear.');
    }

    /**
     * Audit NL2-v: a `WorkerMessageReceivedEvent` listener that throws inside an in-process consume command (no
     * handled / failed / stopped event): the consume frame restores "no message in flight" when it ends.
     */
    public function testAThrowingReceivedListenerInsideAConsumeCommandHeals(): void
    {
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (): never {
            throw new RuntimeException('received listener failed');
        });
        $this->command('app:consume', function (): int {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));

            return Command::SUCCESS;
        });

        $this->runAndCatch('app:consume', 'received listener failed');
        self::assertSame(3, $this->clears, 'Command start, message received, command end.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(5, $this->clears);
    }

    /**
     * Audit NL2-v: a nested main request (in-process HttpKernel::handle() from a controller) does not end the outer
     * request early, so a later command in the outer controller is still not the outermost unit.
     */
    public function testANestedMainRequestDoesNotEndTheOuterRequest(): void
    {
        $this->command('app:probe', static fn (): int => Command::SUCCESS);

        $this->handle(function (): Response {
            $inner = new Request();
            $inner->attributes->set('_controller', static fn (): Response => new Response());
            $this->http->handle($inner);
            self::assertSame(2, $this->clears, 'Outer start, inner start (no command, no message).');
            $this->runCommand('app:probe');
            self::assertSame(2, $this->clears, 'The command still runs inside the outer request.');

            return new Response();
        });
        self::assertSame(3, $this->clears);
    }

    /**
     * Audit NL2-v: an error listener that runs another command leaves a sane state.
     */
    public function testAnErrorListenerThatRunsACommandLeavesASaneState(): void
    {
        $runs = 0;
        $this->dispatcher->addListener(ConsoleEvents::ERROR, function () use (&$runs): void {
            if ($runs++ === 0) {
                $this->runCommand('app:notify');
            }
        });
        $this->command('app:notify', static fn (): int => Command::SUCCESS);
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->runAndCatch('app:failing', 'command failed');
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Audit NL2-v2: a message handler runs a command that fails with a throwing error listener, the message fails and
     * the worker stops: the next main request still clears (failing frames are dropped before the message state ends).
     */
    public function testAFailingCommandInsideAFailedMessageThenAStoppedWorkerStillLetsRequestsClear(): void
    {
        $this->dispatcher->addListener(ConsoleEvents::ERROR, static function (): void {
            throw new LogicException('error listener failed');
        });
        $this->command('app:failing', static function (): int {
            throw new RuntimeException('command failed');
        });

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('handler failed')));
        self::assertSame(2, $this->clears);
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears, 'The next main request clears at its start and at terminate.');

        // Same without the failed event: the stopped worker drops the failing frame first, then ends the message.
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->runAndCatch('app:failing', 'error listener failed');
        $this->dispatcher->dispatch(new WorkerStoppedEvent(new Worker([], $this->createStub(MessageBusInterface::class))));
        $this->handle(static fn (): Response => new Response());
        self::assertSame(7, $this->clears);
    }

    public function testConsoleEventsWithoutACommandChangeNothing(): void
    {
        $this->dispatcher->dispatch(new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()), ConsoleEvents::COMMAND);
        $this->consoleTerminate(); // no command started: unknown command
        self::assertSame(0, $this->clears);
        $this->handle(static fn (): Response => new Response());
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

        (new EntityManagerBoundarySubscriber([$registry], [$this->manager]))->clear();

        self::assertSame(0, $this->clears);
    }

    public function testBoundaryNeverBuildsAnUninitializedManager(): void
    {
        $container = new ContainerBuilder();
        $container->register('registry', stdClass::class)->setPublic(true);
        $container->register('studio_manager', stdClass::class)->setPublic(true);
        $container->register(EntityManagerBoundarySubscriber::class, EntityManagerBoundarySubscriber::class)
            ->setPublic(true)
            ->setArguments([
                new IteratorArgument([new Reference('registry', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
                new IteratorArgument([new Reference('studio_manager', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]),
                'api_studio',
            ]);
        $container->compile();

        $subscriber = $container->get(EntityManagerBoundarySubscriber::class);
        self::assertInstanceOf(EntityManagerBoundarySubscriber::class, $subscriber);
        $subscriber->onMessageReceived();

        self::assertFalse($container->initialized('studio_manager'), 'the boundary does not build the manager');
        self::assertFalse($container->initialized('registry'), 'the boundary does not build the registry');
    }

    private function request(int $type): void
    {
        $request = new Request();
        if ($type === HttpKernelInterface::MAIN_REQUEST) {
            $this->mainRequests[] = $request;
        }
        $this->dispatcher->dispatch(new RequestEvent($this->kernel, $request, $type), KernelEvents::REQUEST);
    }

    private function terminate(): void
    {
        $this->dispatcher->dispatch(new TerminateEvent($this->kernel, $this->finishedMainRequest ?? new Request(), new Response()), KernelEvents::TERMINATE);
    }

    private function finishRequest(int $type): void
    {
        $request = new Request();
        if ($type === HttpKernelInterface::MAIN_REQUEST) {
            $request                   = array_pop($this->mainRequests) ?? $request;
            $this->finishedMainRequest = $request;
        }
        $this->dispatcher->dispatch(new FinishRequestEvent($this->kernel, $request, $type), KernelEvents::FINISH_REQUEST);
    }

    private function consoleCommand(): void
    {
        $command                 = new Command('app:probe');
        $input                   = new ArrayInput([]);
        $this->runningCommands[] = [$command, $input];
        $this->dispatcher->dispatch(new ConsoleCommandEvent($command, $input, new NullOutput()), ConsoleEvents::COMMAND);
    }

    private function consoleTerminate(): void
    {
        [$command, $input] = array_pop($this->runningCommands) ?? [new Command('app:probe'), new ArrayInput([])];
        $this->dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, new NullOutput(), 0), ConsoleEvents::TERMINATE);
    }

    /**
     * A main request through the real HttpKernel, then `kernel.terminate`.
     *
     * @param Closure(): Response $controller
     */
    private function handle(Closure $controller): void
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);
        $response = $this->http->handle($request);
        $this->http->terminate($request, $response);
    }

    /**
     * @param Closure(): int $code
     */
    private function command(string $name, Closure $code): void
    {
        $this->application->addCommand((new Command($name))->setCode($code));
    }

    private function runCommand(string $name): void
    {
        $this->application->run(new ArrayInput(['command' => $name]), new NullOutput());
    }

    private function runAndCatch(string $name, string $message): void
    {
        try {
            $this->runCommand($name);
            self::fail('The run must throw.');
        } catch (RuntimeException|LogicException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
