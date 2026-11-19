<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventListener;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
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
 * failure anywhere in the failure chain (or a `TransientStorageFailure` / transaction-doctrine `ConcurrencyConflict`)
 * answers **409** (or 503, `concurrency.conflict_status`) with `X-Conflict-Code`:
 * - `CONFLICT_001` + `Retry-After` when nothing was committed;
 * - `CONFLICT_002` without `Retry-After` when part of the request was committed (`ConflictCode::forRequest()`: the
 *   failure's own flag, or transaction-doctrine's request commit watermark when the host enables it).
 *
 * JSON routes of the console get `{"error": "concurrency_conflict", "code": …}`; pages get an HTTP exception with the
 * same status and headers, rendered by the host's error page. Other database errors (unique violations, lost
 * connections) are not mapped. When the host registers NowoTransactionDoctrineBundle its responder (priority 64)
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
        private TransientFailureClassifier $classifier = new TransientFailureClassifier(),
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
        if (!$this->isConflict($failure)) {
            return;
        }

        $code    = ConflictCode::forRequest($request, $failure);
        $headers = $this->policy->headersFor($code, $this->hint($failure));
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

        $event->setThrowable($this->policy->httpExceptionFor($code, self::MESSAGE, $failure, $this->hint($failure)));
    }

    public function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof LockConflictExceptionInterface || $current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure);
    }

    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }
}
