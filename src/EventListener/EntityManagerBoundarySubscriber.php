<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;
use SplObjectStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use WeakMap;

use function array_splice;
use function class_exists;
use function count;

use const PHP_INT_MAX;

/**
 * Since 1.12.1 (REQ-RUNTIME-002 rule 4): unit-of-work boundaries of the Api Studio entity manager when it is
 * bundle-owned (`nowo_api_studio.entity_manager` set to a manager other than the host's default one). Without the
 * option the entities live on the host's manager, which this subscriber never touches (clearing it is the host's job).
 *
 * At every boundary a manager that a failed write closed is recreated through the registry, and an open one is cleared,
 * so no managed workspace, service, endpoint or history row of one request, tenant or message survives into the next
 * (stale rows, unbounded identity map, or an unflushed change of a failed unit written by a later flush):
 *
 * - main `kernel.request` (priority 4096) and `kernel.terminate` (priority -4096), only outside a Messenger message and
 *   a console command (a page rendered in-process by a handler or a command, e.g. a PDF or an e-mail, belongs to the
 *   outer unit); sub-requests share the main request;
 * - Messenger `WorkerMessageReceivedEvent` (4096) and `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (-4096);
 * - `console.command` and `console.terminate` (-4096) of the OUTERMOST command only, and only outside a Messenger
 *   message and outside a main request: a command run in-process from another command, a message handler or a
 *   controller (`Application::doRun()`) must not clear the unit of work that runs it (its managed entities and pending
 *   changes stay).
 *
 * Console bookkeeping (1.12.2, audits NM and NL2-v), at the extreme priorities so no other listener can skip it:
 * - every console run is a FRAME on a stack, keyed by its Command object AND its input (a command that runs itself
 *   in-process has a new input per run). `console.command` (priority PHP_INT_MAX) pushes it with the outer state it
 *   started in (Messenger message in flight, main requests in flight) and whether it is the outermost unit (nothing
 *   else running). `console.terminate` (priority PHP_INT_MAX) removes ITS frame and every frame above it (nested runs
 *   that never terminated) and restores the state saved in it; a terminate without a frame removes nothing (never a
 *   blind decrement). The clear at the end of the outermost command still runs at `console.terminate` -4096, for the
 *   verdict computed by the early listener for that very event;
 * - `console.error` (priority PHP_INT_MAX) marks the frame as failing and nothing unmarks it: when an error listener
 *   throws, Symfony never dispatches the command's `console.terminate`, so a frame still marked at the next boundary
 *   (main request start or end, Messenger message received / finished, command, worker stop) is dropped (with the
 *   frames above it) and its saved state restored. Every handler drops such frames FIRST and only then writes its own
 *   state. `console.error` without a command (unknown command) changes nothing;
 * - the end of a main request (`kernel.finish_request` / `kernel.terminate`) and of a Messenger message (handled,
 *   failed, worker stopped) also restores the frames (and, for a request, the message state) to what they were at its
 *   start. The Messenger bookkeeping and the `kernel.terminate` bookkeeping run at priority PHP_INT_MAX; the clears
 *   keep their priorities. A top-level `kernel.terminate` (no command, no message left) also forgets every main request
 *   whose end never ran (a `kernel.exception`, `kernel.finish_request` or `kernel.terminate` listener threw), so no
 *   `Request` is kept and a later top-level command is the outermost unit again (audit NR-a);
 * - main requests in flight are counted per `Request` object, so a nested main request (an in-process
 *   `HttpKernel::handle()` from a controller) never ends the outer one early.
 * So a listener that throws or stops the propagation can no longer leave the process "inside a command" for good
 * (which stopped every later HTTP clear), nor make a nested command clear the outer unit mid-run. The bundle has no
 * `kernel.exception` boundary: `kernel.terminate` is the HTTP end boundary.
 *
 * Changes not flushed by the end of a unit are discarded, as in a PHP-FPM request. An open manager is never replaced
 * (so pending changes of a running unit are never discarded); only a closed one, which can no longer write anything,
 * is recreated through the registry. The manager (set by {@see ApiStudioEntityManagerPass}) and the registry arrive as
 * lazy iterators of `IGNORE_ON_UNINITIALIZED_REFERENCE` references: a boundary never builds them. A closed manager
 * whose registry was never built is left to {@see EntityManagerRecoverySubscriber}.
 * This keeps the bundle correct where the services resetter (which resets Doctrine) does not run: a host that removes
 * or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers started with `--no-reset`, and
 * long-running console commands.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final class EntityManagerBoundarySubscriber implements EventSubscriberInterface
{
    /** A skipped message (only dispatched by `messenger:failed:retry`); Messenger 7.4+, by name. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = 'Symfony\Component\Messenger\Event\WorkerMessageSkipEvent';

    /**
     * Running console commands, outermost first. Each frame keeps the outer state it started in (`message`, `requests`),
     * restored when the frame is removed, and whether it is the outermost unit.
     *
     * @var list<array{command: Command, input: InputInterface, outermost: bool, failing: bool, message: list<mixed>|null, requests: SplObjectStorage<Request, array{0: list<mixed>, 1: list<mixed>|null}>}>
     */
    private array $frames = [];

    /**
     * Main requests in flight (between their `kernel.request` and their `kernel.finish_request` / `kernel.terminate`),
     * in start order, with the frames and the message state at their start.
     *
     * @var SplObjectStorage<Request, array{0: list<mixed>, 1: list<mixed>|null}>
     */
    private SplObjectStorage $requests;

    /**
     * Frames when the in-flight Messenger message was received; null outside a message.
     *
     * @var list<mixed>|null
     */
    private ?array $messageFrames = null;

    /**
     * Outermost verdict of each `console.terminate` event, computed by the early listener for the late clear.
     *
     * @var WeakMap<ConsoleTerminateEvent, bool>
     */
    private WeakMap $verdicts;

    /**
     * @param iterable<ManagerRegistry> $registries the Doctrine registry when it exists (never built by a boundary)
     * @param iterable<EntityManagerInterface> $managers the bundle-owned manager when it exists (empty otherwise)
     * @param string|null $managerName its name in the registry
     */
    public function __construct(
        private readonly iterable $registries = [],
        private readonly iterable $managers = [],
        private readonly ?string $managerName = null,
    ) {
        $this->requests = new SplObjectStorage();
        $this->verdicts = new WeakMap();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST        => ['onKernelRequest', 4096],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', -4096],
            KernelEvents::TERMINATE      => [['onKernelTerminateStart', PHP_INT_MAX], ['onKernelTerminate', -4096]],
        ];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class] = [['onMessageStart', PHP_INT_MAX], ['onMessageReceived', 4096]];
            $events[WorkerMessageHandledEvent::class]  = [['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]];
            $events[WorkerMessageFailedEvent::class]   = [['onMessageEnd', PHP_INT_MAX], ['onMessageFinished', -4096]];
            // A skipped message (only dispatched by `messenger:failed:retry`).
            $events[self::MESSENGER_WORKER_MESSAGE_SKIPPED] = ['onWorkerStopped', PHP_INT_MAX];
            $events[WorkerStoppedEvent::class]              = ['onWorkerStopped', PHP_INT_MAX];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', PHP_INT_MAX];
            $events[ConsoleEvents::ERROR]     = ['onConsoleError', PHP_INT_MAX];
            $events[ConsoleEvents::TERMINATE] = [['onConsoleEnd', PHP_INT_MAX], ['onConsoleTerminate', -4096]];
        }

        return $events;
    }

    /**
     * Start of a main request: clears only outside a command and a message (an in-process HttpKernel::handle()).
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->dropFailingFrames();
        $outermost = $this->isOutsideMessageAndCommand();
        // @igor-ignore - Unit-of-work stack, pushed and popped at the main request boundaries (no request data kept).
        $this->requests[$event->getRequest()] = [$this->frames, $this->messageFrames];
        if ($outermost) {
            $this->clear();
        }
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dropFailingFrames();
            $this->endRequest($event->getRequest());
        }
    }

    /**
     * End of a main request (priority PHP_INT_MAX): ends its bookkeeping before any other listener can throw.
     */
    public function onKernelTerminateStart(TerminateEvent $event): void
    {
        $this->dropFailingFrames();
        $this->endRequest($event->getRequest());
        if ($this->frames === [] && $this->messageFrames === null) {
            // Top-level end: requests whose finish / terminate never ran (a listener threw) are gone too.
            // @igor-ignore - Unit-of-work stack, reset at the top-level request end (no request data kept).
            $this->requests = new SplObjectStorage();
        }
    }

    /**
     * End of a request (priority -4096): clears only outside a command and a message.
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->dropFailingFrames();
        $this->endRequest($event->getRequest());
        if ($this->isOutsideMessageAndCommand()) {
            $this->clear();
        }
    }

    /**
     * Start of a Messenger message (priority PHP_INT_MAX): records it before any other listener can throw.
     */
    public function onMessageStart(): void
    {
        $this->dropFailingFrames();
        // A previous message that never finished ends here.
        $this->endMessage();
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageFrames = $this->frames;
    }

    /**
     * Start of a Messenger message (priority 4096): nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        $this->clear();
    }

    /**
     * End of a Messenger message (handled or failed, priority PHP_INT_MAX): ends its state before any other listener can
     * throw.
     */
    public function onMessageEnd(): void
    {
        $this->dropFailingFrames();
        $this->endMessage();
    }

    /**
     * Message handled or failed (priority -4096): clears.
     */
    public function onMessageFinished(): void
    {
        $this->clear();
    }

    /**
     * The worker stopped, or a message was skipped (`WorkerMessageSkipEvent`, only dispatched by
     * `messenger:failed:retry`): no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // Failing frames first (they restore the state they saved), then this handler's own state.
        $this->dropFailingFrames();
        $this->endMessage();
    }

    /**
     * Start of a command (priority PHP_INT_MAX): pushes its frame, and clears for the outermost command (outside a
     * message, a main request and another command).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (!$command instanceof Command) {
            return;
        }

        $this->dropFailingFrames();
        $outermost = $this->isOutsideMessageAndCommand() && $this->requests->count() === 0;
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames[] = [
            'command'   => $command,
            'input'     => $event->getInput(),
            'outermost' => $outermost,
            'failing'   => false,
            'message'   => $this->messageFrames,
            'requests'  => clone $this->requests,
        ];
        if ($outermost) {
            $this->clear();
        }
    }

    /**
     * A command failed (priority PHP_INT_MAX): marks its frame; a frame still marked when the next unit starts was never
     * terminated (an error listener threw) and is dropped then. Without a command (unknown command) nothing changes.
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index !== null) {
            // @igor-ignore - Failure marker of a running command, removed with its frame.
            $this->frames[$index]['failing'] = true;
        }
    }

    /**
     * End of a command (priority PHP_INT_MAX): removes its frame (and the nested frames above it) and records whether
     * it was the outermost unit; a command without a frame changes nothing.
     */
    public function onConsoleEnd(ConsoleTerminateEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index === null) {
            return;
        }

        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->frames[$index]['outermost'];
        $this->removeFrames($index);
    }

    /**
     * End of a command (priority -4096): clears only when the outermost command ended.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->verdicts[$event] ?? false) {
            $this->clear();
        }
    }

    /**
     * Recreates the bundle-owned manager when it is closed, clears it when it is open; nothing when it was never built.
     */
    public function clear(): void
    {
        foreach ($this->managers as $manager) {
            if (!$manager->isOpen()) {
                $this->recreate();

                continue;
            }
            $manager->clear();
        }
    }

    private function recreate(): void
    {
        if ($this->managerName === null) {
            return;
        }
        foreach ($this->registries as $registry) {
            $registry->resetManager($this->managerName);
        }
    }

    private function isOutsideMessageAndCommand(): bool
    {
        return $this->frames === [] && $this->messageFrames === null;
    }

    private function indexOf(?Command $command, InputInterface $input): ?int
    {
        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command && $this->frames[$i]['input'] === $input) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Removes the frame at $index and the frames above it, and restores the state saved when it started.
     */
    private function removeFrames(int $index): void
    {
        $frame = $this->frames[$index];
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate or self-heal.
        array_splice($this->frames, $index);
        // @igor-ignore - Outer state saved by the frame (no request data kept).
        $this->messageFrames = $frame['message'];
        // @igor-ignore - Outer state saved by the frame (no request data kept).
        $this->requests = $frame['requests'];
    }

    /**
     * Drops the lowest frame whose error listeners threw (Symfony never dispatched its terminate), with the frames above.
     */
    private function dropFailingFrames(): void
    {
        foreach ($this->frames as $index => $frame) {
            if ($frame['failing']) {
                $this->removeFrames($index);

                return;
            }
        }
    }

    private function endRequest(Request $request): void
    {
        if (!isset($this->requests[$request])) {
            return;
        }

        [$frames, $messageFrames] = $this->requests[$request];
        $popped                   = [];
        $found                    = false;
        foreach ($this->requests as $started) {
            $found = $found || $started === $request;
            if ($found) {
                $popped[] = $started;
            }
        }
        foreach ($popped as $started) {
            unset($this->requests[$started]);
        }
        // @igor-ignore - Frames restored at the end of the enclosing request (no request data).
        $this->frames = $frames;
        // @igor-ignore - Message state restored at the end of the enclosing request (no message data).
        $this->messageFrames = $messageFrames;
    }

    private function endMessage(): void
    {
        if ($this->messageFrames === null) {
            return;
        }

        // @igor-ignore - Frames restored at the end of the enclosing message (no message data).
        $this->frames = $this->messageFrames;
        // @igor-ignore - Unit-of-work state, set and unset at the Messenger boundaries (no message data kept).
        $this->messageFrames = null;
    }
}
