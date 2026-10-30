<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\ResponseBodyReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_repeat;
use function strlen;

/**
 * BW audit L2: the remote server chooses the body size; the reader stops at the budget.
 */
final class ResponseBodyReaderTest extends TestCase
{
    public function testBodyWithinTheBudgetIsReturned(): void
    {
        $client = new MockHttpClient(new MockResponse('{"ok":true}'));

        self::assertSame('{"ok":true}', ResponseBodyReader::read($client, $client->request('GET', 'https://api.example.test/'), 1024));
    }

    public function testAnnouncedLengthOverTheBudgetIsRefusedBeforeReading(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 50000), ['response_headers' => ['Content-Length: 50000']]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than 1024 bytes');

        ResponseBodyReader::read($client, $client->request('GET', 'https://api.example.test/'), 1024);
    }

    public function testStreamedBodyOverTheBudgetIsStopped(): void
    {
        // Chunked body without Content-Length: the budget is checked while streaming.
        $chunks = (static function (): iterable {
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('a', 512);
            }
        })();
        $client = new MockHttpClient(new MockResponse($chunks));

        try {
            ResponseBodyReader::read($client, $client->request('GET', 'https://api.example.test/'), 2048);
            self::fail('The oversized body must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('larger than 2048 bytes', $e->getMessage());
        }
    }

    public function testBodyExactlyAtTheBudgetIsAccepted(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('b', 2048)));

        self::assertSame(2048, strlen(ResponseBodyReader::read($client, $client->request('GET', 'https://api.example.test/'), 2048)));
    }
}
