<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function in_array;
use function is_string;

/**
 * Lock conflicts on Api Studio routes (1.17, REQ-RUNTIME-003 rule 6): a deadlock, lock wait timeout or serialization
 * failure anywhere in the failure (or a `TransientStorageFailure` / transaction-doctrine `ConcurrencyConflict`)
 * answers **409** (or 503, `concurrency.conflict_status`) with `X-Conflict-Code`:
 * - `CONFLICT_001` + `Retry-After` when nothing was committed;
 * - `CONFLICT_002` without `Retry-After` when part of the request was committed (`ConflictCode::forRequest()`: the
 *   failure's own flag, or transaction-doctrine's request commit watermark when the host enables it).
 *
 * JSON routes of the console get `{"error": "concurrency_conflict", "code": …}`; pages get an HTTP exception with the
 * same status and headers, rendered by the host's error page. Other database errors (unique violations, lost
 * connections) are not mapped.
 *
 * Detection uses transaction-doctrine's bounded walk (`TransientFailureClassifier::findConflict()`, >= 1.7.1, audit
 * UA2-5): the `getPrevious()` chain and the exceptions a Messenger wrapper carries (a deadlock in the second handler of
 * a synchronous `HandlerFailedException`), each throwable once, at most `ThrowableWalk::MAX_NODES`. A
 * `HandlerFailedException` whose envelope carries a `HandledStamp` answers `CONFLICT_002` (`ConflictCode`). When the host registers NowoTransactionDoctrineBundle its responder (priority 64)
 * answers first; this is the fallback for hosts that do not. Stateless (worker-safe).
 */
final readonly class ConcurrencyConflictSubscriber implements EventSubscriberInterface
{
    public const ERROR = 'concurrency_conflict';

    public const MESSAGE = 'The change met a conflict with another change. Try again.';

    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After transaction-doctrine's responder (64), before the 404 policy (16), the firewall (1) and the error renderer.
        return [KernelEvents::EXCEPTION => ['onKernelException', 32]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !$this->routes->isStudioRoute($route)) {
            return;
        }

        $failure  = $event->getThrowable();
        $conflict = TransientFailureClassifier::findConflict($failure);
        if (!$conflict instanceof Throwable) {
            return;
        }

        $code    = ConflictCode::forRequest($request, $failure);
        $hint    = $this->hint($conflict);
        $headers = $this->policy->headersFor($code, $hint);
        $this->logger?->warning('Api Studio: a write met a database lock conflict; answered the conflict status.', [
            'route'     => $route,
            'status'    => $this->policy->status,
            'code'      => $code->value,
            'exception' => $failure::class,
        ]);

        if (in_array(ApiStudioRoutes::canonical($route), ApiStudioAccessSubscriber::JSON_ROUTES, true)) {
            $event->setResponse(new JsonResponse(['error' => self::ERROR, 'code' => $code->value], $this->policy->status, $headers + ['Cache-Control' => 'no-store, private']));

            return;
        }

        $event->setThrowable($this->policy->httpExceptionFor($code, self::MESSAGE, $failure, $hint));
    }

    /**
     * Whether the failure carries a lock conflict: its getPrevious() chain or a Messenger wrapper's wrapped exceptions
     * (bounded walk). Unique violations and lost connections are never a conflict.
     */
    public function isConflict(Throwable $failure): bool
    {
        return TransientFailureClassifier::findConflict($failure) instanceof Throwable;
    }

    /** The retry hint of the first TransientStorageFailure in the conflict's own getPrevious() chain. */
    private function hint(Throwable $conflict): ?int
    {
        for ($current = $conflict; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }
}
