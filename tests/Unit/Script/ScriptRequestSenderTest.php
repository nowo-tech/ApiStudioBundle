<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Script;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Script\ScriptRequestSender;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_repeat;
use function urldecode;

/**
 * Spec 003 (1.15): pm.sendRequest is sent literally through the console guards.
 */
final class ScriptRequestSenderTest extends TestCase
{
    public function testSendsTheRequestAsBuiltAndReturnsTheResponse(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return new MockResponse('{"ok":true}', ['http_code' => 201, 'response_headers' => ['content-type: application/json']]);
        });

        $response = (new ScriptRequestSender($client, new ExecutionUrlValidator([])))->send([
            'url'    => 'https://93.184.215.14/token?x={{secret}}',
            'method' => 'post',
            'header' => ['X-A' => 'a', 'X-N' => 1],
            'body'   => '{"a":1}',
        ]);

        self::assertSame(201, $response['code']);
        self::assertSame('{"ok":true}', $response['body']);
        self::assertSame('application/json', $response['headers']['content-type']);
        self::assertSame('POST', $calls[0][0]);
        self::assertSame('https://93.184.215.14/token?x={{secret}}', urldecode($calls[0][1]), 'placeholders are never resolved server-side');
        self::assertSame(0, $calls[0][2]['max_redirects']);
        self::assertSame('{"a":1}', $calls[0][2]['body']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refused(): iterable
    {
        yield 'no url' => [[], 'absolute http(s) URL'];
        yield 'relative url' => [['url' => '/api-studio/'], 'absolute http(s) URL'];
        yield 'file url' => [['url' => 'file:///etc/passwd'], 'absolute http(s) URL'];
        yield 'private network' => [['url' => 'http://127.0.0.1/'], 'not allowed'];
        yield 'metadata' => [['url' => 'http://169.254.169.254/latest/'], 'not allowed'];
        yield 'method' => [['url' => 'https://93.184.215.14/', 'method' => 'CONNECT'], 'unsupported method'];
        yield 'header name' => [['url' => 'https://93.184.215.14/', 'header' => ["X-A\r\nX-B" => '1']], 'invalid header'];
        yield 'header value' => [['url' => 'https://93.184.215.14/', 'header' => ['X-A' => ['x']]], 'invalid header'];
        yield 'body type' => [['url' => 'https://93.184.215.14/', 'body' => ['a' => 1]], 'at most 1 MiB'];
        yield 'body size' => [['url' => 'https://93.184.215.14/', 'body' => str_repeat('x', ScriptRequestSender::MAX_BODY_LENGTH + 1)], 'at most 1 MiB'];
    }

    /**
     * @param array<string, mixed> $request
     */
    #[DataProvider('refused')]
    public function testRefusesMalformedOrGuardedRequests(array $request, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ScriptRequestSender(new MockHttpClient(), new ExecutionUrlValidator([])))->send($request);
    }

    public function testAllowlistApplies(): void
    {
        $this->expectExceptionMessage('not allowed');

        (new ScriptRequestSender(new MockHttpClient(), new ExecutionUrlValidator(['api.allowed.test'])))->send(['url' => 'https://93.184.215.14/']);
    }

    public function testTransportFailureNamesOnlyTheExceptionClass(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Could not resolve https://93.184.215.14/?token=REDACTED-TEST'));

        try {
            (new ScriptRequestSender($client, new ExecutionUrlValidator([])))->send(['url' => 'https://93.184.215.14/?token=REDACTED-TEST']);
            self::fail('Expected a failure.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('REDACTED-TEST', $e->getMessage());
            self::assertStringContainsString('TransportException', $e->getMessage());
        }
    }
}
