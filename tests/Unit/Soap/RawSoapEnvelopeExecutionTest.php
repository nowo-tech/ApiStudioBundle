<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Soap;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_filter;
use function array_values;
use function base64_encode;
use function is_string;
use function stripos;

/**
 * Raw-envelope SOAP mode: a body that is a complete SOAP envelope is POSTed as-is through the guarded HTTP client
 * (no WSDL download, no ext-soap), with the same headers, auth, SOAPAction and redirect policy as JSON mode.
 */
final class RawSoapEnvelopeExecutionTest extends TestCase
{
    private const SERVICE_URL = 'https://93.184.215.14/svc/service.asmx?WSDL';

    private const ENDPOINT_URL = 'https://93.184.215.14/svc/service.asmx';

    private const ENVELOPE = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body><Ping xmlns="urn:ping"><value>{{value}}</value></Ping></soap:Body>
        </soap:Envelope>
        XML;

    private const RESPONSE = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><PingResponse xmlns="urn:ping"><result>pong</result></PingResponse></soap:Body></soap:Envelope>';

    private const FAULT = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Client</faultcode><faultstring>Server did not recognize the value of HTTP Header SOAPAction: Ping.</faultstring><detail /></soap:Fault></soap:Body></soap:Envelope>';

    public function testEnvelopeIsPostedRawWithVariablesHeadersAndSoapAction(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return new MockResponse(self::RESPONSE, ['http_code' => 200, 'response_headers' => ['Content-Type: text/xml; charset=utf-8']]);
        });

        $service = $this->service()
            ->setAuthType(AuthType::Basic)
            ->setAuthConfig(['username' => 'u', 'password' => '{{pass}}'])
            ->setDefaultHeaders(['X-Trace' => 'on', 'Content-Type' => 'application/json', 'soapaction' => 'evil']);
        $endpoint = $this->endpoint($service)->setHeaders(['X-Endpoint' => '{{value}}']);

        $result = $this->executor($client, timeout: 7)->execute($endpoint, variables: ['value' => 'hello', 'pass' => 'p4ss']);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertNull($result->errorMessage);
        self::assertSame(200, $result->responseStatus);
        self::assertSame('SOAP', $result->requestMethod);
        self::assertSame(self::ENDPOINT_URL, $result->requestUrl);
        self::assertStringContainsString('pong', (string) $result->responseBody);
        self::assertSame('text/xml; charset=utf-8', $result->responseHeaders['content-type'] ?? null);

        self::assertCount(1, $calls, 'Raw-envelope mode never downloads the WSDL.');
        [$method, $url, $options] = $calls[0];
        self::assertSame(['POST', self::ENDPOINT_URL], [$method, $url]);
        self::assertStringContainsString('<value>hello</value>', (string) $options['body']);
        self::assertSame((string) $result->requestBody, $options['body']);
        self::assertSame(7.0, (float) $options['timeout']);
        self::assertSame(7.0, (float) $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(['SOAPAction: "urn:ping#Ping"'], self::linesNamed($options['headers'], 'SOAPAction'));
        self::assertSame(['Content-Type: text/xml; charset=utf-8'], self::linesNamed($options['headers'], 'Content-Type'));
        self::assertContains('Authorization: Basic ' . base64_encode('u:p4ss'), $options['headers']);
        self::assertContains('X-Trace: on', $options['headers']);
        self::assertContains('X-Endpoint: hello', $options['headers']);
        self::assertSame('"urn:ping#Ping"', $result->requestHeaders['SOAPAction'] ?? null);
    }

    public function testSoap12EnvelopeCarriesActionInContentType(): void
    {
        $captured = null;
        $client   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['headers'];

            return new MockResponse('<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><ok/></env:Body></env:Envelope>');
        });

        $endpoint = $this->endpoint($this->service())
            ->setRequestBodyTemplate('<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><Ping/></env:Body></env:Envelope>');

        $result = $this->executor($client)->execute($endpoint);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertSame(['Content-Type: application/soap+xml; charset=utf-8; action="urn:ping#Ping"'], self::linesNamed($captured, 'Content-Type'));
        self::assertSame([], self::linesNamed($captured, 'SOAPAction'));
    }

    public function testSoapFaultWithHttp500IsSurfacedAsError(): void
    {
        $client = new MockHttpClient(new MockResponse(self::FAULT, ['http_code' => 500]));

        $result = $this->executor($client)->execute($this->endpoint($this->service()), variables: ['value' => 'x']);

        self::assertFalse($result->success);
        self::assertSame(500, $result->responseStatus);
        self::assertSame('SOAP Fault (soap:Client): Server did not recognize the value of HTTP Header SOAPAction: Ping.', $result->errorMessage);
        self::assertSame(self::FAULT, $result->responseBody, 'The fault envelope is kept for display.');
    }

    public function testSoapFaultWithHttp200IsStillAnError(): void
    {
        $client = new MockHttpClient(new MockResponse(self::FAULT, ['http_code' => 200]));

        $result = $this->executor($client)->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertStringStartsWith('SOAP Fault (soap:Client)', (string) $result->errorMessage);
    }

    public function testNonSuccessStatusWithoutFaultIsAnError(): void
    {
        $client = new MockHttpClient(new MockResponse('Service Unavailable', ['http_code' => 503]));

        $result = $this->executor($client)->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertSame(503, $result->responseStatus);
        self::assertSame('SOAP endpoint answered with HTTP 503.', $result->errorMessage);
    }

    public function testRedirectIsNotFollowedByDefault(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.20/soap']);
        });

        $result = $this->executor($client)->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertSame('SOAP endpoint answered with a redirect (HTTP 302) that was not followed.', $result->errorMessage);
        self::assertSame([self::ENDPOINT_URL], $calls);
    }

    public function testRedirectToPrivateNetworkIsBlockedWhenFollowing(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('', ['http_code' => 307, 'redirect_url' => 'http://169.254.169.254/latest/meta-data/']);
        });

        $result = $this->executor($client, maxRedirects: 3)->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertSame([self::ENDPOINT_URL], $calls, 'The private redirect target must never be requested.');
    }

    public function testBlockedEndpointUrlIsNeverRequested(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('A blocked SOAP endpoint must not be requested.');
        });

        $service = $this->service()->setBaseUrl('http://127.0.0.1:8080/svc.asmx?WSDL');
        $result  = $this->executor($client)->execute($this->endpoint($service));

        self::assertFalse($result->success);
        self::assertSame('SOAP endpoint URL is not allowed (SSRF or allowlist).', $result->errorMessage);
        self::assertSame('http://127.0.0.1:8080/svc.asmx', $result->requestUrl);
    }

    public function testEndpointOutsideAllowlistIsRejected(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('A SOAP endpoint outside the allowlist must not be requested.');
        });

        $result = $this->executor($client, allowlist: ['93.184.215.99'])->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertSame('SOAP endpoint URL is not allowed (SSRF or allowlist).', $result->errorMessage);
    }

    public function testTransportErrorIsReported(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $result = $this->executor($client)->execute($this->endpoint($this->service()));

        self::assertFalse($result->success);
        self::assertStringContainsString('Connection refused', (string) $result->errorMessage);
        self::assertNull($result->responseStatus);
    }

    public function testOverrideBodyEnvelopeSelectsRawMode(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options['body'] ?? null];

            return new MockResponse(self::RESPONSE);
        });

        $endpoint = $this->endpoint($this->service())->setRequestBodyTemplate('{"value": "json"}');
        $envelope = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><Ping/></soapenv:Body></soapenv:Envelope>';

        $result = $this->executor($client)->execute($endpoint, overrideBody: $envelope);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertSame([['POST', self::ENDPOINT_URL, $envelope]], $calls);
    }

    /**
     * @param mixed $lines normalized "Name: value" header lines from the mock client options
     *
     * @return list<string>
     */
    private static function linesNamed(mixed $lines, string $name): array
    {
        self::assertIsArray($lines);

        return array_values(array_filter($lines, static fn (mixed $line): bool => is_string($line) && stripos($line, $name . ':') === 0));
    }

    /**
     * @param list<string> $allowlist
     */
    private function executor(MockHttpClient $client, int $timeout = 5, array $allowlist = [], int $maxRedirects = 0): RequestExecutor
    {
        return new RequestExecutor(new NoPrivateNetworkHttpClient($client), new VariableResolver(), new ExecutionUrlValidator($allowlist), $timeout, null, $maxRedirects);
    }

    private function service(): ApiService
    {
        return (new ApiService('Soap', 'soap'))
            ->setProtocol(ApiProtocol::Soap)
            ->setBaseUrl(self::SERVICE_URL)
            ->setAuthType(AuthType::None);
    }

    private function endpoint(ApiService $service): ApiEndpoint
    {
        return (new ApiEndpoint('Ping', 'ping'))
            ->setSoapAction('urn:ping#Ping')
            ->setContentType('application/json')
            ->setRequestBodyTemplate(self::ENVELOPE)
            ->setService($service);
    }
}
