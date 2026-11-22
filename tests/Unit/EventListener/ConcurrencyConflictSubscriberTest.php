<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function array_slice;

/**
 * 1.17 (REQ-RUNTIME-003 rule 6): lock conflicts on Api Studio routes answer 409 / 503 with X-Conflict-Code,
 * CONFLICT_001 + Retry-After or CONFLICT_002 without it; anything else is left alone.
 *
 * 1.21: a domain stale edit is not one of them — always 409 with `X-Error-Code: API_STUDIO_STALE_EDIT`, never
 * `X-Conflict-Code` and never `Retry-After`.
 */
final class ConcurrencyConflictSubscriberTest extends TestCase
{
    public function testAPageConflictBecomesA409WithCodeAndRetryAfter(): void
    {
        $logger = new InMemoryLogger();
        $event  = $this->event('nowo_api_studio_workspace_edit', new TransientStorageFailure(3));
        (new ConcurrencyConflictSubscriber(logger: $logger))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '3'], $exception->getHeaders());
        self::assertSame(ConcurrencyConflictSubscriber::MESSAGE, $exception->getMessage());
        self::assertStringContainsString('CONFLICT_001', $logger->text());
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', 32]], ConcurrencyConflictSubscriber::getSubscribedEvents());
    }

    public function testAJsonRouteGetsTheJsonShapeAlsoOnATwin(): void
    {
        $event = $this->event('nowo_api_studio_scoped_execute', $this->deadlock());
        (new ConcurrencyConflictSubscriber(new ConflictResponsePolicy(503, 5)))->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"error":"concurrency_conflict","code":"CONFLICT_001"}', $response->getContent());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testCommittedWorkAnswersConflict002WithoutRetryAfter(): void
    {
        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true);
        $event     = $this->event('nowo_api_studio_workspace_new', $committed);
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $exception->getHeaders());

        // A commit earlier in the same request (transaction-doctrine watermark) also means CONFLICT_002.
        $ledger = new CommitLedger();
        $event  = $this->event('nowo_api_studio_workspace_new', new TransientStorageFailure(1));
        $event->getRequest()->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        (new ConcurrencyConflictSubscriber())->onKernelException($event);
        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame('CONFLICT_002', $exception->getHeaders()['X-Conflict-Code']);
    }

    public function testOtherFailuresAndOtherRoutesAreLeftAlone(): void
    {
        $subscriber = new ConcurrencyConflictSubscriber();
        foreach ([
            ['nowo_api_studio_workspace_new', new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('dup')), null)],
            ['nowo_api_studio_workspace_new', new RuntimeException('domain')],
            ['app_home', new TransientStorageFailure(1)],
            [null, new TransientStorageFailure(1)],
        ] as [$route, $failure]) {
            $event = $this->event($route, $failure);
            $subscriber->onKernelException($event);
            self::assertSame($failure, $event->getThrowable());
            self::assertNull($event->getResponse());
        }
    }

    public function testARawDeadlockWithoutARetryHintUsesTheConfiguredRetryAfter(): void
    {
        $event = $this->event('nowo_api_studio_workspace_new', $this->deadlock());
        (new ConcurrencyConflictSubscriber(new ConflictResponsePolicy(409, 7)))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame('7', $exception->getHeaders()['Retry-After']);
    }

    /** UA2-5: a deadlock in the second handler of a synchronous HandlerFailedException answers the conflict status. */
    public function testADeadlockAsTheSecondWrappedExceptionAnswers409(): void
    {
        $failure = new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('first handler'), $this->deadlock()]);
        self::assertTrue((new ConcurrencyConflictSubscriber())->isConflict($failure));

        $event = $this->event('nowo_api_studio_workspace_new', $failure);
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame('CONFLICT_001', $exception->getHeaders()['X-Conflict-Code']);
        self::assertSame($failure, $exception->getPrevious());
    }

    /** A handler already succeeded (HandledStamp): part of the message was handled, so CONFLICT_002 without Retry-After. */
    public function testAWrappedDeadlockAfterAHandledStampAnswersConflict002(): void
    {
        $envelope = new Envelope(new stdClass(), [new HandledStamp('ok', 'App\\FirstHandler::__invoke')]);
        $event    = $this->event('nowo_api_studio_scoped_execute', new HandlerFailedException($envelope, [$this->deadlock()]));
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    /** The retry hint of a TransientStorageFailure found inside a wrapper is kept. */
    public function testAWrappedTransientStorageFailureKeepsItsRetryHint(): void
    {
        $failure = new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('first'), new TransientStorageFailure(4)]);
        $event   = $this->event('nowo_api_studio_workspace_new', $failure);
        (new ConcurrencyConflictSubscriber(new ConflictResponsePolicy(409, 9)))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '4'], $exception->getHeaders());
    }

    /** A unique violation (or a lost connection) wrapped the same way is never a conflict: the 500 stays. */
    public function testAUniqueViolationAsTheSecondWrappedExceptionIsNotAConflict(): void
    {
        $unique  = new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('dup')), null);
        $failure = new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('first handler'), $unique]);
        $event   = $this->event('nowo_api_studio_workspace_new', $failure);
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        self::assertSame($failure, $event->getThrowable());
        self::assertNull($event->getResponse());
        self::assertFalse((new ConcurrencyConflictSubscriber())->isConflict($failure));

        // Host routes are never answered, even with a wrapped deadlock.
        $hostFailure = new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('first'), $this->deadlock()]);
        $event       = $this->event('app_checkout', $hostFailure);
        (new ConcurrencyConflictSubscriber())->onKernelException($event);
        self::assertSame($hostFailure, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    /** The walk is bounded: a deadlock beyond ThrowableWalk::MAX_NODES is not reached, one within it is. */
    public function testTheWalkIsBounded(): void
    {
        $subscriber = new ConcurrencyConflictSubscriber();

        $padding = [];
        for ($i = 1; $i < ThrowableWalk::MAX_NODES; ++$i) {
            $padding[] = new RuntimeException('handler ' . $i);
        }

        // The wrapper plus MAX_NODES - 1 handlers fill the cap: the deadlock after them is never reached (no 409).
        $beyond = new HandlerFailedException(new Envelope(new stdClass()), [...$padding, $this->deadlock()]);
        self::assertFalse($subscriber->isConflict($beyond));

        // One handler less: the deadlock is the last throwable the walk visits.
        $within = new HandlerFailedException(new Envelope(new stdClass()), [...array_slice($padding, 1), $this->deadlock()]);
        self::assertTrue($subscriber->isConflict($within));
    }

    public function testAStaleEditOnAPageIsADomain409WithoutAConflictCode(): void
    {
        $logger = new InMemoryLogger();
        $stale  = new StaleEditException();
        $event  = $this->event('nowo_api_studio_workspace_edit', $stale);
        (new ConcurrencyConflictSubscriber(new ConflictResponsePolicy(503, 5), logger: $logger))->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        // 409 even with conflict_status 503: that status is the lock-conflict answer, not this one.
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['X-Error-Code' => 'API_STUDIO_STALE_EDIT'], $exception->getHeaders());
        self::assertArrayNotHasKey('X-Conflict-Code', $exception->getHeaders());
        self::assertArrayNotHasKey('Retry-After', $exception->getHeaders());
        self::assertSame(ConcurrencyConflictSubscriber::STALE_EDIT_MESSAGE, $exception->getMessage());
        self::assertSame($stale, $exception->getPrevious());
        self::assertStringContainsString('API_STUDIO_STALE_EDIT', $logger->text());
        self::assertStringNotContainsString('CONFLICT_001', $logger->text());
    }

    public function testAStaleEditOnAJsonRouteGetsTheDomainJsonShape(): void
    {
        $event = $this->event('nowo_api_studio_endpoint_save_documentation', new StaleEditException());
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"error":"stale_edit","code":"API_STUDIO_STALE_EDIT"}', $response->getContent());
        self::assertSame('API_STUDIO_STALE_EDIT', $response->headers->get('X-Error-Code'));
        self::assertFalse($response->headers->has('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** A stale edit wrapped by Messenger, and the lock conflict it does not become. */
    public function testAWrappedStaleEditKeepsTheDomainAnswerAndIsNoConflict(): void
    {
        $wrapped = new RuntimeException('save', 0, new StaleEditException());
        $event   = $this->event('nowo_api_studio_service_edit', $wrapped);
        (new ConcurrencyConflictSubscriber())->onKernelException($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['X-Error-Code' => 'API_STUDIO_STALE_EDIT'], $exception->getHeaders());

        // Not a lock conflict for transaction-doctrine either (no LockConflictExceptionInterface since 1.21).
        self::assertFalse((new ConcurrencyConflictSubscriber())->isConflict(new StaleEditException()));

        // A host route keeps the stale edit untouched.
        $hostEvent = $this->event('app_checkout', new StaleEditException());
        (new ConcurrencyConflictSubscriber())->onKernelException($hostEvent);
        self::assertInstanceOf(StaleEditException::class, $hostEvent->getThrowable());
        self::assertNull($hostEvent->getResponse());
    }

    private function deadlock(): Throwable
    {
        $pdo            = new PDOException('Deadlock found when trying to get lock', 40001);
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found'];

        return new DeadlockException(PdoDriverException::new($pdo), null);
    }

    private function event(?string $route, Throwable $failure): ExceptionEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
