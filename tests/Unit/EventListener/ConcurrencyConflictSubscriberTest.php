<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * 1.17 (REQ-RUNTIME-003 rule 6): lock conflicts on Api Studio routes answer 409 / 503 with X-Conflict-Code,
 * CONFLICT_001 + Retry-After or CONFLICT_002 without it; anything else is left alone.
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
