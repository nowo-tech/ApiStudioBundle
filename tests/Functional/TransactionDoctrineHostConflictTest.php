<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Tests\Functional\App\TransactionDoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1.21 (REQ-RUNTIME-003 rule 6, the convention accepted 2026-11-22) on a host that registers
 * `NowoTransactionDoctrineBundle` **and** set `concurrency.conflict_status: 503`: its conflict responder runs at
 * priority 64, before the bundle's subscriber (32), and answers anything marked as a lock conflict with the retryable
 * `CONFLICT_001` + `Retry-After`. That is how a stale edit was answered in 1.20, and no other test kernel registers
 * that bundle.
 *
 * These tests pin the split on the host that exposes it: a domain stale edit is a plain **409** with
 * `X-Error-Code: API_STUDIO_STALE_EDIT`, no `X-Conflict-Code` and no `Retry-After` (not the configured 503 either),
 * while a real deadlock keeps the whole retryable contract. Putting the marker interface back on
 * `StaleEditException`, or a host mapper claiming it, turns them red.
 */
final class TransactionDoctrineHostConflictTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TransactionDoctrineTestKernel::class;
    }

    public function testAStaleEditIsADomain409OnATransactionDoctrineHost(): void
    {
        $response = $this->answerOf('/probe/stale');

        self::assertSame(409, $response->getStatusCode(), 'a domain refusal, not the configured 503');
        self::assertSame('API_STUDIO_STALE_EDIT', $response->headers->get('X-Error-Code'));
        self::assertFalse($response->headers->has('X-Conflict-Code'), 'the transaction contract never claims it');
        self::assertFalse($response->headers->has('Retry-After'), 'the refusal is permanent');
    }

    public function testAWrappedStaleEditIsAnsweredTheSameWay(): void
    {
        $response = $this->answerOf('/probe/wrapped');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('API_STUDIO_STALE_EDIT', $response->headers->get('X-Error-Code'));
        self::assertFalse($response->headers->has('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testARealDeadlockKeepsTheRetryableLockConflictContract(): void
    {
        $response = $this->answerOf('/probe/deadlock');

        // Whichever responder answers it: the host's at priority 64, or the bundle's at 32 with the configured 503.
        self::assertContains($response->getStatusCode(), [409, TransactionDoctrineTestKernel::CONFLICT_STATUS]);
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertTrue($response->headers->has('Retry-After'), 'a deadlock is retryable');
        self::assertFalse($response->headers->has('X-Error-Code'));
    }

    private function answerOf(string $path): Response
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->request('GET', $path);

        return $client->getResponse();
    }
}
