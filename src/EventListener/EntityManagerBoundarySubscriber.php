<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

use function class_exists;
use function max;

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
 * - `console.command` (4096) and `console.terminate` (-4096) of the OUTERMOST command only, and only outside a Messenger
 *   message and outside a main request: a command run in-process from another command, a message handler or a
 *   controller (`Application::doRun()`) must not clear the unit of work that runs it (its managed entities and pending
 *   changes stay). Console depth counter; message flag set on received and unset on handled / failed / worker stopped;
 *   request flag set on the main `kernel.request` and unset on its `kernel.finish_request`.
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
    /**
     * Console commands running in this process (nested in-process runs count). Process-lifetime counter bounded by
     * the nesting depth (no request data).
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed). */
    private bool $messageInFlight = false;

    /** A main request is being handled (between its `kernel.request` and its `kernel.finish_request`). */
    private bool $requestInFlight = false;

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
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST        => ['onKernelRequest', 4096],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', -4096],
            KernelEvents::TERMINATE      => ['onKernelTerminate', -4096],
        ];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class] = ['onMessageReceived', 4096];
            $events[WorkerMessageHandledEvent::class]  = ['onMessageFinished', -4096];
            $events[WorkerMessageFailedEvent::class]   = ['onMessageFinished', -4096];
            // A listener may skip a received message (no handled / failed event follows).
            $events[WorkerStoppedEvent::class] = ['onWorkerStopped', -4096];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', 4096];
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -4096];
        }

        return $events;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
        $this->requestInFlight = true;
        if ($this->isOutsideMessageAndCommand()) {
            $this->clear();
        }
    }

    /**
     * End of a main request: clears only outside a message and a command (an in-process `http_kernel->handle()` run
     * by a message handler or a command, e.g. to render a PDF or an e-mail, ends inside the outer unit).
     */
    public function onKernelTerminate(): void
    {
        if ($this->isOutsideMessageAndCommand()) {
            $this->clear();
        }
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
            $this->requestInFlight = false;
        }
    }

    public function onMessageReceived(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->clear();
    }

    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clear();
    }

    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    public function onConsoleCommand(): void
    {
        if ($this->isOutermostUnit()) {
            $this->clear();
        }
        // @igor-ignore - Nesting counter of the running commands (no request data).
        ++$this->consoleDepth;
    }

    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->isOutermostUnit()) {
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

    private function isOutermostUnit(): bool
    {
        return $this->isOutsideMessageAndCommand() && !$this->requestInFlight;
    }

    private function isOutsideMessageAndCommand(): bool
    {
        return $this->consoleDepth === 0 && !$this->messageInFlight;
    }
}
