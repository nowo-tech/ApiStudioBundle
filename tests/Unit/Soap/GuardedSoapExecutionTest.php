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
use Nowo\ApiStudioBundle\Soap\GuardedSoapClient;
use Nowo\ApiStudioBundle\Soap\GuardedWsdlLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SoapFault;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_filter;
use function array_values;
use function base64_encode;
use function glob;
use function is_string;
use function stripos;
use function sys_get_temp_dir;

use const SOAP_1_1;
use const SOAP_1_2;

/**
 * SOAP executions never let ext-soap reach the network: the WSDL, its imports and the call to
 * <soap:address location> all go through the guarded HTTP client (SSRF + allowlist + rebinding checks).
 */
#[RequiresPhpExtension('soap')]
final class GuardedSoapExecutionTest extends TestCase
{
    private const WSDL_URL = 'https://93.184.215.14/svc/service.wsdl';

    private const RESPONSE = '<?xml version="1.0"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="urn:ping"><SOAP-ENV:Body><ns1:PingResponse><result>pong</result></ns1:PingResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    public function testAllowedLocationIsCalledThroughGuardedClient(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse(self::RESPONSE, ['http_code' => 200]);
        });

        $tempFilesBefore = self::tempWsdlFiles();
        $result          = $this->executor($client, timeout: 9)->execute($this->endpoint());

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertStringContainsString('pong', (string) $result->responseBody);
        self::assertStringContainsString('Ping', (string) $result->requestBody);
        self::assertCount(2, $calls);
        self::assertSame(['GET', self::WSDL_URL], [$calls[0][0], $calls[0][1]]);
        self::assertSame(['POST', 'https://93.184.215.14/soap'], [$calls[1][0], $calls[1][1]]);
        self::assertSame(9.0, (float) $calls[1][2]['timeout']);
        self::assertSame(9.0, (float) $calls[1][2]['max_duration']);
        self::assertContains('SOAPAction: "urn:ping#Ping"', $calls[1][2]['headers']);
        self::assertSame($tempFilesBefore, self::tempWsdlFiles(), 'Temporary WSDL copies must be removed.');
    }

    public function testJsonModeCallsOperationNamedByLastSegmentOfSoapActionUri(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse(self::RESPONSE, ['http_code' => 200]);
        });

        $result = $this->executor($client)->execute($this->endpoint()->setSoapAction('urn:ping#Ping'));

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertCount(2, $calls, 'A JSON body still uses the WSDL + SoapClient mode.');
        self::assertStringContainsString('Ping', (string) $result->requestBody);
        self::assertContains('SOAPAction: "urn:ping#Ping"', $calls[1][2]['headers']);
    }

    #[DataProvider('internalLocations')]
    public function testInternalSoapAddressLocationIsRejected(string $location): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls, $location): MockResponse {
            $calls[] = $url;

            return new MockResponse(self::wsdl($location));
        });

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('SOAP endpoint location is not allowed', (string) $result->errorMessage);
        self::assertSame([self::WSDL_URL], $calls, 'The internal location must never be requested.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalLocations(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback' => ['http://127.0.0.1:8080/admin'];
        yield 'localhost' => ['http://localhost/soap'];
    }

    public function testLocationOutsideAllowlistIsRejected(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse(self::wsdl('https://93.184.215.15/soap'));
        });

        $result = $this->executor($client, allowlist: ['93.184.215.14'])->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('SOAP endpoint location is not allowed', (string) $result->errorMessage);
        self::assertSame([self::WSDL_URL], $calls);
    }

    public function testRebindingOfSoapLocationToPrivateIpIsBlocked(): void
    {
        $client = new MockHttpClient(static fn (string $method): MockResponse => $method === 'GET'
            ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
            : new MockResponse(self::RESPONSE, ['primary_ip' => '10.0.0.5']));

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('10.0.0.5', (string) $result->errorMessage);
    }

    public function testSoapCallRedirectToPublicHostIsFollowedWhenAllowed(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return match (true) {
                $method === 'GET'                     => new MockResponse(self::wsdl('https://93.184.215.14/soap')),
                $url === 'https://93.184.215.14/soap' => new MockResponse('', ['http_code' => 307, 'redirect_url' => 'https://93.184.215.20/soap']),
                default                               => new MockResponse(self::RESPONSE),
            };
        });

        $result = $this->executor($client, maxRedirects: 3)->execute($this->endpoint());

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertStringContainsString('pong', (string) $result->responseBody);
        self::assertSame(['POST', 'https://93.184.215.20/soap'], [$calls[2][0], $calls[2][1]]);
        self::assertContains('SOAPAction: "urn:ping#Ping"', $calls[2][2]['headers']);
    }

    public function testSoapCallRedirectIsNotFollowedByDefault(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.20/soap']);
        });

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('HTTP 302', (string) $result->errorMessage);
        self::assertSame([self::WSDL_URL, 'https://93.184.215.14/soap'], $calls);
    }

    #[DataProvider('privateRedirectTargets')]
    public function testSoapCallRedirectToPrivateNetworkIsBlocked(string $target): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls, $target): MockResponse {
            $calls[] = [$method, $url];

            return match (true) {
                $method === 'GET'                     => new MockResponse(self::wsdl('https://93.184.215.14/soap')),
                $url === 'https://93.184.215.14/soap' => new MockResponse('', ['http_code' => 307, 'redirect_url' => $target, 'response_headers' => ['Location' => $target]]),
                default                               => new MockResponse(self::RESPONSE),
            };
        });

        $result = $this->executor($client, maxRedirects: 3)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertSame(
            [['GET', self::WSDL_URL], ['POST', 'https://93.184.215.14/soap']],
            $calls,
            'The redirect hop to a private address must never be requested.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateRedirectTargets(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'RFC1918' => ['http://10.0.0.5/soap'];
        yield 'IPv6 loopback' => ['http://[::1]/soap'];
    }

    public function testWsdlRedirectToLoopbackIsBlocked(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://127.0.0.1/internal.wsdl', 'response_headers' => ['Location' => 'http://127.0.0.1/internal.wsdl']]);
        });

        $result = $this->executor($client, maxRedirects: 3)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertSame([self::WSDL_URL], $calls);
    }

    public function testWsdlRedirectIsNotFollowedByDefault(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 302, 'redirect_url' => 'https://93.184.215.14/other.wsdl', 'response_headers' => ['Location' => 'https://93.184.215.14/other.wsdl']]));

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('HTTP 302', (string) $result->errorMessage);
    }

    public function testWsdlImportPointingToMetadataHostIsRejected(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse(self::wsdl('https://93.184.215.14/soap', 'http://169.254.169.254/latest/types.xsd'));
        });

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('WSDL document URL is not allowed', (string) $result->errorMessage);
        self::assertSame([self::WSDL_URL], $calls);
    }

    public function testWsdlImportWithLocalFileIsRejected(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(self::wsdl('https://93.184.215.14/soap', 'file:///etc/passwd')));

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('WSDL document URL is not allowed', (string) $result->errorMessage);
    }

    #[DataProvider('importReferences')]
    public function testWsdlImportsAreFetchedThroughGuardedClient(string $reference, string $expectedUrl): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls, $reference): MockResponse {
            $calls[] = $url;

            return match (true) {
                $url === self::WSDL_URL => new MockResponse(self::wsdl('https://93.184.215.14/soap', $reference)),
                $method === 'GET'       => new MockResponse(self::schema()),
                default                 => new MockResponse(self::RESPONSE),
            };
        });

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertSame([self::WSDL_URL, $expectedUrl, 'https://93.184.215.14/soap'], $calls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function importReferences(): iterable
    {
        yield 'relative' => ['types.xsd', 'https://93.184.215.14/svc/types.xsd'];
        yield 'absolute path' => ['/schemas/types.xsd', 'https://93.184.215.14/schemas/types.xsd'];
        yield 'scheme relative' => ['//93.184.215.14/types.xsd', 'https://93.184.215.14/types.xsd'];
        yield 'absolute url' => ['https://93.184.215.14:8443/types.xsd', 'https://93.184.215.14:8443/types.xsd'];
    }

    public function testWsdlWithDoctypeIsRejected(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><d>&x;</d>'));

        $result = $this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('DTD', (string) $result->errorMessage);
    }

    public function testInvalidWsdlXmlIsRejected(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('not xml'));

        $result = @$this->executor($client)->execute($this->endpoint());

        self::assertFalse($result->success);
        self::assertStringContainsString('not valid XML', (string) $result->errorMessage);
    }

    public function testSoap12UsesActionInContentTypeAndOneWayReturnsNull(): void
    {
        $captured = [];
        $client   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options['headers'];

            return new MockResponse(self::RESPONSE);
        });

        $soap = new GuardedSoapClient(null, ['location' => 'https://93.184.215.14/soap', 'uri' => 'urn:ping'], $client, new ExecutionUrlValidator([]), 5, 0);

        self::assertNull($soap->__doRequest('<x/>', 'https://93.184.215.14/soap', 'urn:ping#Ping', SOAP_1_2, true));
        self::assertSame(self::RESPONSE, $soap->__doRequest('<x/>', 'https://93.184.215.14/soap', 'urn:ping#Ping', SOAP_1_1));
        self::assertContains('Content-Type: application/soap+xml; charset=utf-8; action="urn:ping#Ping"', $captured[0]);
        self::assertContains('Content-Type: text/xml; charset=utf-8', $captured[1]);
    }

    public function testDirectCallToBlockedLocationThrowsSoapFault(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('Blocked locations must not be requested.');
        });

        $soap = new GuardedSoapClient(null, ['location' => 'http://169.254.169.254/', 'uri' => 'urn:ping'], $client, new ExecutionUrlValidator([]), 5, 0);

        $this->expectException(SoapFault::class);
        $soap->__doRequest('<x/>', 'http://169.254.169.254/', 'urn:ping#Ping', SOAP_1_1);
    }

    /**
     * @param array<string, mixed> $authConfig
     */
    #[DataProvider('authSchemes')]
    public function testAuthHeaderIsSentWithWsdlFetchAndSoapCall(AuthType $authType, array $authConfig, string $expectedLine): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse(self::RESPONSE);
        });

        $endpoint = $this->endpoint();
        $endpoint->getService()?->setAuthType($authType)->setAuthConfig($authConfig);

        $result = $this->executor($client)->execute($endpoint, null, null, [], [], ['secret' => 's3cr3t']);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertCount(2, $calls);
        self::assertContains($expectedLine, $calls[0][2]['headers'], 'The WSDL fetch must carry the auth header.');
        self::assertContains($expectedLine, $calls[1][2]['headers'], 'The SOAP call must carry the auth header.');
    }

    /**
     * @return iterable<string, array{AuthType, array<string, string>, string}>
     */
    public static function authSchemes(): iterable
    {
        yield 'bearer' => [AuthType::Bearer, ['token' => '{{secret}}'], 'Authorization: Bearer s3cr3t'];
        yield 'basic' => [AuthType::Basic, ['username' => 'user', 'password' => '{{secret}}'], 'Authorization: Basic ' . base64_encode('user:s3cr3t')];
        yield 'api key (custom header)' => [AuthType::ApiKey, ['header' => 'X-Partner-Key', 'value' => '{{secret}}'], 'X-Partner-Key: s3cr3t'];
        yield 'api key (default header)' => [AuthType::ApiKey, ['value' => '{{secret}}'], 'X-Api-Key: s3cr3t'];
    }

    public function testDefaultAndEndpointHeadersAreSentAndFramingHeadersCannotBeOverridden(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options];

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse(self::RESPONSE);
        });

        $endpoint = $this->endpoint()
            ->setContentType('application/json')
            ->setHeaders(['X-Tenant' => '{{tenant}}', 'content-type' => 'application/json', 'SOAPAction' => '"urn:evil#Drop"']);
        $endpoint->getService()?->setDefaultHeaders(['X-Client' => 'api-studio', 'soapaction' => 'other']);

        $result = $this->executor($client)->execute($endpoint, null, null, [], [], ['tenant' => 'acme']);

        self::assertTrue($result->success, (string) $result->errorMessage);
        $sent = $calls[1][2]['headers'];
        self::assertContains('X-Tenant: acme', $sent);
        self::assertContains('X-Client: api-studio', $sent);
        self::assertContains('X-Tenant: acme', $calls[0][2]['headers']);
        self::assertSame(['Content-Type: text/xml; charset=utf-8'], self::linesNamed($sent, 'content-type'));
        self::assertSame(['SOAPAction: "urn:ping#Ping"'], self::linesNamed($sent, 'soapaction'));
        self::assertSame([], self::linesNamed($calls[0][2]['headers'], 'content-type'), 'The WSDL GET carries no Content-Type.');
        self::assertSame(['X-Client' => 'api-studio', 'X-Tenant' => 'acme'], $result->requestHeaders);
    }

    public function testConsoleHeadersReplaceDefaultsButAuthIsStillApplied(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $options['headers'];

            return $method === 'GET'
                ? new MockResponse(self::wsdl('https://93.184.215.14/soap'))
                : new MockResponse(self::RESPONSE);
        });

        $endpoint = $this->endpoint()->setHeaders(['X-Default' => 'yes']);
        $endpoint->getService()?->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 'tok']);

        $result = $this->executor($client)->execute($endpoint, null, null, ['X-Console' => '1'], [], null, false, true);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertContains('X-Console: 1', $calls[1]);
        self::assertContains('Authorization: Bearer tok', $calls[1]);
        self::assertSame([], self::linesNamed($calls[1], 'x-default'));
    }

    public function testHeadersAreSentToSameOriginImportsOnly(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[$url] = $options['headers'];

            return match (true) {
                $url === self::WSDL_URL => new MockResponse(self::wsdl('https://93.184.215.14/soap', 'types.xsd', 'https://93.184.215.99/third-party.xsd')),
                $method === 'GET'       => new MockResponse(self::schema()),
                default                 => new MockResponse(self::RESPONSE),
            };
        });

        $endpoint = $this->endpoint();
        $endpoint->getService()?->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 'tok']);

        $result = $this->executor($client)->execute($endpoint);

        self::assertTrue($result->success, (string) $result->errorMessage);
        self::assertContains('Authorization: Bearer tok', $calls[self::WSDL_URL]);
        self::assertContains('Authorization: Bearer tok', $calls['https://93.184.215.14/svc/types.xsd']);
        self::assertSame([], self::linesNamed($calls['https://93.184.215.99/third-party.xsd'], 'authorization'), 'Credentials must not leak to another origin.');
        self::assertContains('Authorization: Bearer tok', $calls['https://93.184.215.14/soap']);
    }

    public function testLoaderRejectsUrlWithoutHostBeforeSendingHeaders(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('A URL without host must not be requested.');
        });

        $loader = new GuardedWsdlLoader($client, new ExecutionUrlValidator([]), 5, 0, ['Authorization' => 'Bearer t']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WSDL document URL is not allowed');
        $loader->load('service.wsdl');
    }

    public function testExtraHeadersOnDirectClientDropFramingNamesForSoap12(): void
    {
        $captured = [];
        $client   = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['headers'];

            return new MockResponse(self::RESPONSE);
        });

        $soap = new GuardedSoapClient(
            null,
            ['location' => 'https://93.184.215.14/soap', 'uri' => 'urn:ping'],
            $client,
            new ExecutionUrlValidator([]),
            5,
            0,
            ['Authorization' => 'Bearer t', 'SOAPAction' => 'x', 'CONTENT-TYPE' => 'text/plain', '' => 'empty'],
        );

        $soap->__doRequest('<x/>', 'https://93.184.215.14/soap', 'urn:ping#Ping', SOAP_1_2);

        self::assertContains('Authorization: Bearer t', $captured);
        self::assertSame(['Content-Type: application/soap+xml; charset=utf-8; action="urn:ping#Ping"'], self::linesNamed($captured, 'content-type'));
        self::assertSame([], self::linesNamed($captured, 'soapaction'));
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

    private function endpoint(): ApiEndpoint
    {
        $service = (new ApiService('Soap', 'soap'))
            ->setProtocol(ApiProtocol::Soap)
            ->setBaseUrl(self::WSDL_URL)
            ->setAuthType(AuthType::None);

        return (new ApiEndpoint('Ping', 'ping'))
            ->setSoapAction('Ping')
            ->setRequestBodyTemplate('{"value": "hello"}')
            ->setService($service);
    }

    private static function wsdl(string $location, ?string ...$schemaLocations): string
    {
        $includes = '';
        foreach ($schemaLocations as $schemaLocation) {
            $includes .= $schemaLocation === null ? '' : '<xsd:include schemaLocation="' . $schemaLocation . '"/>';
        }
        $types = $includes === '' ? '' : '<types><xsd:schema targetNamespace="urn:ping">' . $includes . '</xsd:schema></types>';

        return <<<XML
            <?xml version="1.0"?>
            <definitions name="Ping" targetNamespace="urn:ping" xmlns:tns="urn:ping" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns="http://schemas.xmlsoap.org/wsdl/">
              {$types}
              <message name="PingRequest"><part name="value" type="xsd:string"/></message>
              <message name="PingResponse"><part name="result" type="xsd:string"/></message>
              <portType name="PingPort"><operation name="Ping"><input message="tns:PingRequest"/><output message="tns:PingResponse"/></operation></portType>
              <binding name="PingBinding" type="tns:PingPort">
                <soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>
                <operation name="Ping">
                  <soap:operation soapAction="urn:ping#Ping"/>
                  <input><soap:body use="literal" namespace="urn:ping"/></input>
                  <output><soap:body use="literal" namespace="urn:ping"/></output>
                </operation>
              </binding>
              <service name="PingService"><port name="PingPort" binding="tns:PingBinding"><soap:address location="{$location}"/></port></service>
            </definitions>
            XML;
    }

    private static function schema(): string
    {
        return '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:ping"><xsd:simpleType name="Code"><xsd:restriction base="xsd:string"/></xsd:simpleType></xsd:schema>';
    }

    /**
     * @return list<string>
     */
    private static function tempWsdlFiles(): array
    {
        return glob(sys_get_temp_dir() . '/api_studio_wsdl_*') ?: [];
    }
}
