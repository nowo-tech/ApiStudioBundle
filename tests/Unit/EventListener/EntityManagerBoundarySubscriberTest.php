<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Closure;
use Countable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiStudioBundle\EventListener\EntityManagerBoundarySubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

use function array_key_last;
use function array_pop;
use function count;
use function gc_collect_cycles;

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

    /** The HTTP kernel's request stack, shared with the subscriber. */
    private RequestStack $requestStack;

    private EntityManagerBoundarySubscriber $subscriber;

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
        $this->requestStack = new RequestStack();
        $this->subscriber   = new EntityManagerBoundarySubscriber([$this->registry], [$this->manager], 'api_studio', $this->requestStack);
        $this->dispatcher->addSubscriber($this->subscriber);
        $this->http        = new HttpKernel($this->dispatcher, new ControllerResolver(), $this->requestStack, new ArgumentResolver());
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
        self::assertSame([['onKernelTerminateStart', PHP_INT_MAX], ['onKernelTerminate', -4096]], $events[KernelEvents::TERMINATE]);
        self::assertSame([['onMessageStart', PHP_INT_MAX], ['onMessageReceived', 4096], ['onMessageReceivedDecided', PHP_INT_MIN]], $events[WorkerMessageReceivedEvent::class]);
        self::assertSame([['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageHandledEvent::class]);
        self::assertSame([['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]], $events[WorkerMessageFailedEvent::class]);
        self::assertSame(['onWorkerStopped', PHP_INT_MAX], $events[WorkerStoppedEvent::class]);
        self::assertSame(['onMessageEnd', PHP_INT_MAX], $events[WorkerMessageSkipEvent::class]);
        self::assertSame(['onWorkerStarted', PHP_INT_MAX], $events[WorkerStartedEvent::class]);
        self::assertSame(['onWorkerRunning', PHP_INT_MAX], $events[WorkerRunningEvent::class]);
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
            self::assertSame(1, $this->clears, 'Outer start only: the nested main request is not the outermost unit (audit NX CR2).');
            $this->runCommand('app:probe');
            self::assertSame(1, $this->clears, 'The command still runs inside the outer request.');

            return new Response();
        });
        self::assertSame(2, $this->clears);
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

    /**
     * Audit NR-a (P13): a throwing `kernel.exception` listener skips the request's finish and terminate; later requests
     * still clear, the next top-level terminate forgets the stale request, and a later top-level command is outermost.
     */
    public function testAThrowingExceptionListenerLeavesNoStaleRequest(): void
    {
        $throw = static function (): void {
            throw new LogicException('exception listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw);
        $failing = new Request();
        $failing->attributes->set('_controller', static function (): Response {
            throw new RuntimeException('controller failed');
        });
        try {
            $this->http->handle($failing);
            self::fail('The request must throw.');
        } catch (LogicException $e) {
            self::assertSame('exception listener failed', $e->getMessage());
        }
        $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        self::assertSame(1, $this->clears);

        $this->handle(static fn (): Response => new Response());
        self::assertSame(3, $this->clears);
        self::assertSame(0, $this->requestsInFlight(), 'The top-level terminate forgot the stale request.');
        $this->command('app:probe', static fn (): int => Command::SUCCESS);
        $this->runCommand('app:probe');
        self::assertSame(5, $this->clears, 'A later top-level command is the outermost unit.');
    }

    /**
     * Audit NR-a (P8): `kernel.finish_request` and `kernel.terminate` listeners that throw never leave requests in
     * flight for good (no unbounded storage, later top-level commands still clear).
     */
    public function testThrowingFinishAndTerminateListenersLeaveNoStaleRequest(): void
    {
        $throw = static function (): void {
            throw new LogicException('listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::FINISH_REQUEST, $throw, 0);
        $this->dispatcher->addListener(KernelEvents::TERMINATE, $throw, 0);
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->handle(static fn (): Response => new Response());
                self::fail('The request must throw.');
            } catch (LogicException $e) {
                self::assertSame('listener failed', $e->getMessage());
            }
        }
        $this->dispatcher->removeListener(KernelEvents::FINISH_REQUEST, $throw);
        $this->dispatcher->removeListener(KernelEvents::TERMINATE, $throw);

        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
        self::assertSame(0, $this->requestsInFlight());
        $this->command('app:probe', static fn (): int => Command::SUCCESS);
        $this->runCommand('app:probe');
        self::assertSame($before + 4, $this->clears, 'A later top-level command is the outermost unit.');
    }

    /**
     * Audit NR-a (P6): in-process Worker events inside a request whose `WorkerMessageFailedEvent` listener throws
     * before the late clear: the message state already ended, so later requests still clear.
     */
    public function testAThrowingFailedListenerInsideARequestDoesNotStopLaterClears(): void
    {
        $this->dispatcher->addListener(WorkerMessageFailedEvent::class, static function (): void {
            throw new RuntimeException('retry transport down');
        }, 0);

        $this->handle(function (): Response {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            try {
                $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('handler failed')));
                self::fail('The failed listener must throw.');
            } catch (RuntimeException $e) {
                self::assertSame('retry transport down', $e->getMessage());
            }

            return new Response();
        });
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Audit NR-a (P7): a `WorkerMessageReceivedEvent` listener that throws for an in-process Worker inside a request (no
     * command): the request end restores the message state it started with, so later requests still clear.
     */
    public function testAThrowingReceivedListenerInsideARequestDoesNotStopLaterClears(): void
    {
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, static function (): void {
            throw new RuntimeException('received listener failed');
        }, 0);

        $this->handle(function (): Response {
            try {
                $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
                self::fail('The received listener must throw.');
            } catch (RuntimeException $e) {
                self::assertSame('received listener failed', $e->getMessage());
            }

            return new Response();
        });
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
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
        $subscriber->onMessageReceived(new stdClass());

        self::assertFalse($container->initialized('studio_manager'), 'the boundary does not build the manager');
        self::assertFalse($container->initialized('registry'), 'the boundary does not build the registry');
    }

    /**
     * Audit NX CR1: a worker run in-process inside a message handler, then a main request in the same handler: neither
     * clears the outer message's unit; the outer message clears when received and when handled.
     */
    public function testAWorkerInsideAHandlerNeverEndsTheOuterMessage(): void
    {
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $before = $this->clears;
            $this->runWorker(1, static function (): void {
            });
            $this->http->handle($this->controllerRequest(static fn (): Response => new Response('in handler')));
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside, 'The inner worker and the in-process request keep the outer message.');
        self::assertSame(2, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(4, $this->clears, 'The next main request clears at its start and terminate.');
    }

    /**
     * Audit NX CR3: a received listener skips the message with `shouldHandle(false)` (no handled / failed event); with
     * no worker event at all (CR3b) the message ends too.
     */
    public function testASkippedMessageEndsWithoutAHandledEvent(): void
    {
        $received = 0;
        $skip     = static function (WorkerMessageReceivedEvent $event) use (&$received): void {
            if (++$received === 1) {
                $event->shouldHandle(false);
            }
        };
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $skip);
        // The in-memory transport delivers the skipped (never acknowledged) message again.
        $this->runWorker(1, static function (): void {
        });
        $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $skip);
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);

        $event = new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async');
        $event->shouldHandle(false);
        $this->dispatcher->dispatch($event);
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * A message dispatched synchronously (no worker) inside a request belongs to the request.
     */
    public function testASynchronousMessageInsideARequestBelongsToTheRequest(): void
    {
        $handled = 0;
        $bus     = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [static function () use (&$handled): void {
            ++$handled;
        }]]))]);
        $inside = null;
        $this->handle(function () use ($bus, &$inside): Response {
            $before = $this->clears;
            $bus->dispatch(new stdClass());
            $inside = $this->clears - $before;

            return new Response();
        });

        self::assertSame(1, $handled);
        self::assertSame(0, $inside);
        self::assertSame(2, $this->clears);
    }

    /**
     * A `messenger:consume`-like command runs a worker in-process: every message is an outermost unit inside it.
     */
    public function testAWorkerInsideACommandClearsAtEveryMessage(): void
    {
        $this->command('app:consume', function (): int {
            $this->runWorker(2, static function (): void {
            });

            return Command::SUCCESS;
        });
        $this->runCommand('app:consume');

        self::assertSame(2 + 2 * 2, $this->clears, 'The command start / end and every message received / handled.');
        $this->handle(static fn (): Response => new Response());
        self::assertSame(8, $this->clears);
    }

    /**
     * Two workers one after the other; the first one's stop never came (a WorkerRunningEvent listener threw out of
     * `Worker::run()`): the second worker's messages are still outermost units.
     */
    public function testTwoSequentialWorkersKeepTheirOwnState(): void
    {
        $this->runWorker(1, static function (): void {
        });
        self::assertSame(2, $this->clears);

        $throw = static function (): never {
            throw new RuntimeException('running listener failed');
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $throw);
        try {
            $this->runWorker(1, static function (): void {
            });
            self::fail('The running listener throws.');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $throw);
        }

        $before = $this->clears;
        $this->runWorker(1, static function (): void {
        });
        self::assertSame($before + 2, $this->clears, 'The second worker\'s message clears when received and when handled.');
    }

    /**
     * Unbalanced events: another worker's running / stopped events inside a message do not end it; a nested main
     * request whose end never came is popped with its outer request.
     */
    public function testUnbalancedEventsNeverEndAnotherUnit(): void
    {
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $stranger = new Worker([], $this->createStub(MessageBusInterface::class));
            $this->dispatcher->dispatch(new WorkerRunningEvent($stranger, false));
            $this->dispatcher->dispatch(new WorkerStoppedEvent($stranger));
            $before = $this->clears;
            $this->http->handle($this->controllerRequest(static fn (): Response => new Response('in handler')));
            $inside = $this->clears - $before;
        });
        self::assertSame(0, $inside, 'Another worker\'s events do not end this message.');

        $this->handle(function (): Response {
            // A nested main request whose finish_request / terminate never came.
            $this->dispatcher->dispatch(new RequestEvent($this->http, new Request(), HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
            self::assertSame(2, $this->requestsInFlight());

            return new Response();
        });
        self::assertSame(0, $this->requestsInFlight());
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Exceptions in the middle of a unit: a failing handler, and a received listener that throws out of an in-process
     * worker outside any command (the next top-level terminate resets the stacks).
     */
    public function testAnExceptionMidUnitNeverLeavesAMessageInFlight(): void
    {
        $this->runWorker(1, static function (): never {
            throw new RuntimeException('handler failed');
        });
        self::assertSame(2, $this->clears, 'Received and failed.');

        $throw = static function (): never {
            throw new RuntimeException('received listener failed');
        };
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
        try {
            $this->runWorker(1, static function (): void {
            });
            self::fail('The received listener throws.');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
        }
        $this->handle(static fn (): Response => new Response());
        $before = $this->clears;
        $this->handle(static fn (): Response => new Response());
        self::assertSame($before + 2, $this->clears);
    }

    /**
     * Audit NX: a started event without a worker changes nothing, a worker started again (its stop never came) keeps
     * one entry, an outer message that ends while a nested worker never stopped drops that worker, and a running
     * event ends its worker's message even when no handled / failed event came.
     */
    public function testWorkerEntriesSurviveLostEvents(): void
    {
        $outer  = new Worker([], $this->createStub(MessageBusInterface::class));
        $nested = new Worker([], $this->createStub(MessageBusInterface::class));

        $this->subscriber->onWorkerStarted(new stdClass());
        $this->dispatcher->dispatch(new WorkerStartedEvent($outer));
        $this->dispatcher->dispatch(new WorkerStartedEvent($outer));
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->dispatcher->dispatch(new WorkerStartedEvent($nested));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
        self::assertSame(2, $this->clears, 'The outer message clears when received and when handled.');

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        $this->subscriber->onWorkerRunning(new WorkerRunningEvent($outer, false));
        $this->subscriber->onWorkerRunning(new stdClass());
        self::assertSame(3, $this->clears);
        $this->handle(static fn (): Response => new Response());
        self::assertSame(5, $this->clears, 'No message is left in flight.');
    }

    /**
     * Audit OB-M1 (S8): a long-running command runs workers in a loop and catches their exceptions; the first run aborts
     * in a throwing received listener. Every later message still clears when received and when handled.
     */
    public function testAnAbortedWorkerInsideALongCommandNeverStopsTheNextMessagesFromClearing(): void
    {
        $perWorker = [];
        $this->command('app:loop', function () use (&$perWorker): int {
            $throw = static function (): never {
                throw new RuntimeException('received listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
            try {
                $this->runWorker(1, static function (): void {
                });
            } catch (RuntimeException) {
            } finally {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
            }

            for ($i = 0; $i < 3; ++$i) {
                $before = $this->clears;
                $this->runWorker(2, static function (): void {
                });
                $perWorker[] = $this->clears - $before;
            }

            return Command::SUCCESS;
        });
        $this->runCommand('app:loop');

        self::assertSame([4, 4, 4], $perWorker);
    }

    /**
     * Audit OB-L1 (S13): 500 runs aborted by a throwing handled listener inside one command leave a bounded stack, and
     * the next worker's messages are still the outermost units.
     */
    public function testAbortedRunsNeverGrowTheWorkerStack(): void
    {
        $entries = null;
        $clears  = null;
        $this->command('app:loop', function () use (&$entries, &$clears): int {
            $throw = static function (): never {
                throw new RuntimeException('handled listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageHandledEvent::class, $throw, -1);
            for ($i = 0; $i < 500; ++$i) {
                try {
                    $this->runWorker(1, static function (): void {
                    });
                } catch (RuntimeException) {
                }
            }
            $this->dispatcher->removeListener(WorkerMessageHandledEvent::class, $throw);
            gc_collect_cycles();
            $before = $this->clears;
            $this->runWorker(2, static function (): void {
            });
            $clears  = $this->clears - $before;
            $workers = (new ReflectionProperty($this->subscriber, 'workers'))->getValue($this->subscriber);
            self::assertIsArray($workers);
            $entries = count($workers);

            return Command::SUCCESS;
        });
        $this->runCommand('app:loop');

        self::assertSame(4, $clears);
        self::assertSame(0, $entries, 'no aborted run is kept');
    }

    /**
     * Audit OB-L2 (S9): a received listener of an inner worker dispatches `WorkerMessageSkipEvent` and skips the
     * message: only the inner message ends, a main request in the outer handler does not clear.
     */
    public function testASkipEventFromAReceivedListenerNeverEndsTheOuterMessage(): void
    {
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $skip = function (WorkerMessageReceivedEvent $event): void {
                $this->dispatcher->dispatch(new WorkerMessageSkipEvent($event->getEnvelope(), $event->getReceiverName()));
                $event->shouldHandle(false);
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $skip);
            $this->runWorker(1, static function (): void {
            }, true);
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $skip);
            $before = $this->clears;
            $this->handle(static fn (): Response => new Response('in handler'));
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside);
    }

    /**
     * Audit OB-L3 (S10): the in-process `kernel.terminate` of a nested main request does not clear the outer request.
     */
    public function testTheTerminateOfANestedMainRequestKeepsTheOuterRequest(): void
    {
        $inside = null;
        $this->handle(function () use (&$inside): Response {
            $before = $this->clears;
            $this->handle(static fn (): Response => new Response('nested'));
            $inside = $this->clears - $before;

            return new Response();
        });

        self::assertSame(0, $inside);
        self::assertSame(2, $this->clears);
    }

    /**
     * Audit OB2-L1 (S14): host code leaves the kernel's request stack unbalanced (a push without a pop), then a request
     * throws out of `handle()` (no finish_request, no terminate). The kernel's main request is stuck from then on, yet
     * the next top-level requests still clear at their start and at their terminate.
     */
    public function testAnUnbalancedRequestStackNeverStopsTheNextRequestsFromClearing(): void
    {
        $this->handle(function (): Response {
            $this->requestStack->push(new Request());

            return new Response('unbalanced');
        });
        $throw = static function (): never {
            throw new RuntimeException('exception listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw);
        $request = new Request();
        $request->attributes->set('_controller', static function (): never {
            throw new LogicException('controller failed');
        });
        try {
            $this->http->handle($request);
            self::fail('The kernel.exception listener must throw.');
        } catch (RuntimeException) {
            // No kernel.finish_request, no kernel.terminate.
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        }
        self::assertNotNull($this->requestStack->getMainRequest(), 'The host left a request on the kernel stack.');

        for ($i = 0; $i < 2; ++$i) {
            $before  = $this->clears;
            $atStart = null;
            $this->handle(function () use ($before, &$atStart): Response {
                $atStart = $this->clears - $before;

                return new Response();
            });
            self::assertSame(1, $atStart, 'The next main request must clear at its start.');
            self::assertSame(2, $this->clears - $before, 'The next main request must clear at kernel.terminate.');
            self::assertSame(0, $this->requestsInFlight());
        }
    }

    /**
     * Runs a real Messenger worker over $count messages until it is idle.
     *
     * @param Closure(): void $handler
     */
    private function runWorker(int $count, Closure $handler, bool $stopAfterFirst = false): void
    {
        $transport = new InMemoryTransport();
        for ($i = 0; $i < $count; ++$i) {
            $transport->send(new Envelope(new stdClass()));
        }

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($handler): Envelope {
            $handler();

            return $message instanceof Envelope ? $message : new Envelope($message);
        });
        $worker = new Worker(['async' => $transport], $bus, $this->dispatcher);
        $stop   = static function (WorkerRunningEvent $event) use ($worker, $stopAfterFirst): void {
            if ($event->getWorker() === $worker && ($stopAfterFirst || $event->isWorkerIdle())) {
                $worker->stop();
            }
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $stop, -1);
        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $stop);
        }
    }

    /**
     * @param Closure(): Response $controller
     */
    private function controllerRequest(Closure $controller): Request
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);

        return $request;
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
        // Symfony passes the main request itself (finished or not, e.g. when its finish_request never came).
        $request = $this->finishedMainRequest ?? $this->mainRequests[array_key_last($this->mainRequests) ?? 0] ?? new Request();
        $this->dispatcher->dispatch(new TerminateEvent($this->kernel, $request, new Response()), KernelEvents::TERMINATE);
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

    private function requestsInFlight(): int
    {
        $requests = (new ReflectionProperty($this->subscriber, 'requests'))->getValue($this->subscriber);
        self::assertInstanceOf(Countable::class, $requests);

        return count($requests);
    }
}
