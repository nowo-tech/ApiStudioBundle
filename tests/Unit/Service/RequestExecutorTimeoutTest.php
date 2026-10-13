<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * REQ-RUNTIME-001: outbound HTTP uses ui.request_timeout_seconds.
 */
final class RequestExecutorTimeoutTest extends TestCase
{
    public function testRestRequestPassesConfiguredTimeoutToHttpClient(): void
    {
        $capturedOptions = null;
        $client          = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $executor = new RequestExecutor(
            $client,
            new VariableResolver(),
            new ExecutionUrlValidator([]),
            17,
        );

        $result = $executor->execute($this->endpoint());

        self::assertTrue($result->success);
        self::assertIsArray($capturedOptions);
        self::assertSame(17.0, (float) $capturedOptions['timeout']);
        self::assertSame(17.0, (float) $capturedOptions['max_duration']);
        self::assertSame(0, $capturedOptions['max_redirects']);
    }

    public function testRedirectToLoopbackIsNotFollowedByDefault(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://127.0.0.1/admin']]);
        });

        $executor = new RequestExecutor($client, new VariableResolver(), new ExecutionUrlValidator([]), 5);

        $result = $executor->execute($this->endpoint());

        self::assertSame(['https://93.184.215.14/ping'], $calls);
        self::assertSame(302, $result->responseStatus);
    }

    public function testConnectionToPrivateIpIsBlockedAfterPreflight(): void
    {
        // Simulates DNS rebinding: the pre-flight check saw a public address, the connection lands on 10.0.0.5.
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('secret', ['http_code' => 200, 'primary_ip' => '10.0.0.5']));

        $executor = new RequestExecutor($client, new VariableResolver(), new ExecutionUrlValidator([]), 5);

        $result = $executor->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertNull($result->responseBody);
        self::assertStringContainsString('10.0.0.5', (string) $result->errorMessage);
    }

    public function testExplicitRedirectsArePassedToClient(): void
    {
        $capturedOptions = null;
        $client          = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('{}', ['http_code' => 200]);
        });

        $executor = new RequestExecutor(new NoPrivateNetworkHttpClient($client), new VariableResolver(), new ExecutionUrlValidator([]), 5, null, 3);
        $executor->execute($this->endpoint());

        self::assertIsArray($capturedOptions);
        // NoPrivateNetworkHttpClient follows redirects itself (checking each hop) and disables them on the inner client.
        self::assertSame(0, $capturedOptions['max_redirects']);
    }

    public function testTimeoutExceptionIsReturnedAsFailedExecution(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TimeoutException('Idle timeout reached');
        });

        $executor = new RequestExecutor(
            $client,
            new VariableResolver(),
            new ExecutionUrlValidator([]),
            1,
        );

        $result = $executor->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('Idle timeout reached', (string) $result->errorMessage);
    }

    public function testExecuteFailsWhenUrlBlockedBySsrf(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called for SSRF-blocked URLs');
        });

        $executor = new RequestExecutor(
            $client,
            new VariableResolver(),
            new ExecutionUrlValidator([]),
            5,
        );

        $service = (new ApiService('Internal', 'internal'))
            ->setProtocol(ApiProtocol::Rest)
            ->setBaseUrl('http://127.0.0.1')
            ->setAuthType(AuthType::None);

        $endpoint = (new ApiEndpoint('Admin', 'admin'))
            ->setMethod(HttpMethod::Get)
            ->setPath('/admin')
            ->setService($service);

        $result = $executor->execute($endpoint);

        self::assertFalse($result->success);
        self::assertStringContainsString('not allowed', (string) $result->errorMessage);
    }

    public function testExecuteFailsWhenUrlFailsAllowlist(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP client must not be called when allowlist rejects the URL');
        });

        $executor = new RequestExecutor(
            $client,
            new VariableResolver(),
            new ExecutionUrlValidator(['https://allowed.example.com']),
            5,
        );

        $result = $executor->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('not allowed', (string) $result->errorMessage);
    }

    private function endpoint(): ApiEndpoint
    {
        $service = (new ApiService('Demo', 'demo'))
            ->setProtocol(ApiProtocol::Rest)
            ->setBaseUrl('https://93.184.215.14')
            ->setAuthType(AuthType::None);

        return (new ApiEndpoint('Ping', 'ping'))
            ->setMethod(HttpMethod::Get)
            ->setPath('/ping')
            ->setService($service);
    }
}
