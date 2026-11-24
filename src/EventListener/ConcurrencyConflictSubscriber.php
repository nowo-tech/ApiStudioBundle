<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Controller\RowVersionEditTrait;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCodeResolver;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function in_array;
use function is_string;

/**
 * Lock conflicts on Api Studio routes (1.17, REQ-RUNTIME-003 rule 6): a deadlock, lock wait timeout or serialization
 * failure anywhere in the failure (or a `TransientStorageFailure` / transaction-doctrine `ConcurrencyConflict`)
 * answers **409** (or 503, `concurrency.conflict_status`) with `X-Conflict-Code`:
 * - `CONFLICT_001` + `Retry-After` when nothing was committed;
 * - `CONFLICT_002` without `Retry-After` when part of the request was committed (`ConflictCodeResolver::forRequest()`: the
 *   failure's own flag, or transaction-doctrine's request commit watermark when the host enables it).
 *
 * JSON routes of the console get `{"error": "concurrency_conflict", "code": …}`; pages get an HTTP exception with the
 * same status and headers, rendered by the host's error page. Other database errors (unique violations, lost
 * connections) are not mapped.
 *
 * A **domain** stale edit ({@see StaleEditException}, an edit form saved on a row version that is no longer current) is
 * none of that and is answered first (1.21, house convention): always **409** with
 * `X-Error-Code: API_STUDIO_STALE_EDIT`, no `X-Conflict-Code` and no `Retry-After`, because nothing was written and
 * repeating the request can never succeed. Until 1.20 it answered `CONFLICT_001` + `Retry-After`, which invited an
 * endless retry. {@see answerForStaleEdit()} is the answer the edit pages use themselves.
 *
 * Detection uses transaction-doctrine's bounded walk (`TransientFailureClassifier::findConflict()`, >= 1.7.1, audit
 * UA2-5): the `getPrevious()` chain and the exceptions a Messenger wrapper carries (a deadlock in the second handler of
 * a synchronous `HandlerFailedException`), each throwable once, at most `ThrowableWalk::MAX_NODES`. A
 * `HandlerFailedException` whose envelope carries a `HandledStamp` answers `CONFLICT_002` (`ConflictCodeResolver`). When the host registers NowoTransactionDoctrineBundle its responder (priority 64)
 * answers first; this is the fallback for hosts that do not. Stateless (worker-safe).
 */
final readonly class ConcurrencyConflictSubscriber implements EventSubscriberInterface
{
    public const ERROR = 'concurrency_conflict';

    public const MESSAGE = 'The change met a conflict with another change. Try again.';

    /** Message of a stale-edit answer: never "try again" — the same request can only be refused again. */
    public const STALE_EDIT_MESSAGE = 'The item was saved by someone else after the form was rendered. Open it again before saving.';

    /** Most throwables walked when looking for a stale edit (a long chain can never hang a worker). */
    private const MAX_DEPTH = 256;

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

        $failure = $event->getThrowable();

        // A domain stale edit that no page answered itself: 409 + X-Error-Code, never a retryable CONFLICT_00x.
        if (self::findStaleEdit($failure) instanceof StaleEditException) {
            $this->answerStaleEdit($event, $route, $failure);

            return;
        }

        $conflict = TransientFailureClassifier::findConflict($failure);
        if (!$conflict instanceof Throwable) {
            return;
        }

        $code    = ConflictCodeResolver::forRequest($request, $failure);
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
     * Status and headers of a domain stale edit (1.21): always 409 with `X-Error-Code: API_STUDIO_STALE_EDIT`, no
     * `X-Conflict-Code` and no `Retry-After`. The answer the edit forms use when they show the form again with the
     * stored data ({@see RowVersionEditTrait::answerStaleEdit()}).
     *
     * @return array{int, array<string, string>}
     */
    public function answerForStaleEdit(Request $request): array
    {
        $route = $request->attributes->get('_route');
        $this->logger?->info('Api Studio: an edit met a newer version of the row; answered the stale-edit status.', [
            'route'  => is_string($route) ? $route : null,
            'status' => StaleEditException::STATUS,
            'code'   => StaleEditException::ERROR_CODE,
        ]);

        return [StaleEditException::STATUS, [StaleEditException::HEADER => StaleEditException::ERROR_CODE]];
    }

    /** The stale edit the failure carries, walking a bounded `getPrevious()` chain (null when it carries none). */
    private static function findStaleEdit(Throwable $failure): ?StaleEditException
    {
        for ($current = $failure, $seen = 0; $current instanceof Throwable && $seen < self::MAX_DEPTH; $current = $current->getPrevious(), ++$seen) {
            if ($current instanceof StaleEditException) {
                return $current;
            }
        }

        return null;
    }

    /** The answer of a stale edit that reached the kernel: the JSON shape of the console routes, a 409 page elsewhere. */
    private function answerStaleEdit(ExceptionEvent $event, string $route, Throwable $failure): void
    {
        [$status, $headers] = $this->answerForStaleEdit($event->getRequest());

        if (in_array(ApiStudioRoutes::canonical($route), ApiStudioAccessSubscriber::JSON_ROUTES, true)) {
            $event->setResponse(new JsonResponse(
                ['error' => StaleEditException::ERROR, 'code' => StaleEditException::ERROR_CODE],
                $status,
                $headers + ['Cache-Control' => 'no-store, private'],
            ));

            return;
        }

        $event->setThrowable(new ConflictHttpException(self::STALE_EDIT_MESSAGE, $failure, 0, $headers));
    }

    /**
     * Status and headers of the lock-conflict answer for a failure a page answers itself (a domain stale edit uses
     * {@see answerForStaleEdit()} instead): the same policy as the exception answer — the configured status (409 or 503),
     * `X-Conflict-Code: CONFLICT_001` + `Retry-After` when nothing was committed, `CONFLICT_002` without `Retry-After`
     * when the failure or the request's commit watermark says part was committed.
     *
     * @return array{int, array<string, string>}
     */
    public function answerFor(Request $request, Throwable $failure): array
    {
        $code     = ConflictCodeResolver::forRequest($request, $failure);
        $conflict = TransientFailureClassifier::findConflict($failure);
        $route    = $request->attributes->get('_route');
        $this->logger?->info('Api Studio: a write met a lock conflict a page answers itself; answered the conflict status.', [
            'route'  => is_string($route) ? $route : null,
            'status' => $this->policy->status,
            'code'   => $code->value,
        ]);

        return [$this->policy->status, $this->policy->headersFor($code, $conflict instanceof Throwable ? $this->hint($conflict) : null)];
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
