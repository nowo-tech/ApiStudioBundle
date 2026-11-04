<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Closure;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Model\ApiExecutionResult;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function base64_encode;
use function json_encode;

/**
 * REST / GraphQL request building: URL, query, headers, auth, body and the outcome logged without secrets.
 */
final class RequestExecutorTest extends TestCase
{
    private const BASE = 'https://93.184.215.14';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logs = [];

    public function testEndpointWithoutServiceFails(): void
    {
        $result = $this->executor()->execute(new ApiEndpoint('Orphan', 'orphan'));

        self::assertFalse($result->success);
        self::assertSame('Endpoint has no service.', $result->errorMessage);
        self::assertSame([], $this->calls);
    }

    public function testBuildsUrlQueryHeadersAndBodyFromEnvironmentVariables(): void
    {
        $environment = new ApiEnvironment('Dev', 'dev');
        $environment->addVariable(new ApiEnvironmentVariable('host', self::BASE));
        $environment->addVariable(new ApiEnvironmentVariable('id', '7'));
        $endpoint = $this->endpoint('{{host}}/', 'orders/{{id}}')
            ->setMethod(HttpMethod::Put)
            ->setQueryParams(['expand' => 'lines', 'page' => '1'])
            ->setHeaders(['X-Trace' => 'endpoint'])
            ->setRequestBodyTemplate('{"id": {{id}}}');
        $endpoint->getService()?->setDefaultHeaders(['X-Trace' => 'service', 'X-Service' => 'yes']);

        $result = $this->executor()->execute($endpoint, $environment, null, ['X-Extra' => '1'], ['page' => '2']);

        self::assertTrue($result->success);
        self::assertSame(self::BASE . '/orders/7?expand=lines&page=2', $result->requestUrl);
        self::assertSame('PUT', $result->requestMethod);
        self::assertSame('{"id": 7}', $result->requestBody);
        self::assertSame(['X-Trace' => 'endpoint', 'X-Service' => 'yes', 'X-Extra' => '1', 'Content-Type' => 'application/json'], $result->requestHeaders);
        self::assertSame('{"id": 7}', $this->calls[0][2]['body']);
        self::assertSame(['content-type' => 'application/json'], $result->responseHeaders);
        self::assertSame('info', $this->logs[1][0]);
        self::assertSame(self::BASE, $this->logs[1][2]['host']);
    }

    public function testReplaceModesUseOnlyTheConsoleValuesAndGetDropsTheBody(): void
    {
        $endpoint = $this->endpoint(self::BASE, '/items')
            ->setQueryParams(['default' => '1'])
            ->setHeaders(['X-Default' => '1'])
            ->setContentType('')
            ->setRequestBodyTemplate('ignored for GET');

        $result = $this->executor()->execute($endpoint, null, null, ['X-Only' => 'yes'], ['only' => 'yes'], ['unused' => 'x'], true, true);

        self::assertSame(self::BASE . '/items?only=yes', $result->requestUrl);
        self::assertSame(['X-Only' => 'yes'], $result->requestHeaders);
        self::assertSame('', $this->calls[0][2]['body'] ?? '', 'GET requests carry no body');
        self::assertSame('ignored for GET', $result->requestBody);
    }

    public function testMethodOverrideAndNonSuccessStatusIsLoggedAsWarning(): void
    {
        $result = $this->executor(new MockResponse('nope', ['http_code' => 404]))
            ->execute($this->endpoint(self::BASE, '/x'), null, 'payload', methodOverride: HttpMethod::Delete);

        self::assertFalse($result->success);
        self::assertSame(404, $result->responseStatus);
        self::assertSame('DELETE', $this->calls[0][0]);
        self::assertSame('payload', $this->calls[0][2]['body']);
        self::assertSame('warning', $this->logs[1][0]);
    }

    /**
     * @return iterable<string, array{AuthType, array<string, string>, array<string, string>}>
     */
    public static function authentications(): iterable
    {
        yield 'basic' => [AuthType::Basic, ['username' => '{{user}}', 'password' => 'REDACTED-TEST'], ['Authorization' => 'Basic ' . base64_encode('alice:REDACTED-TEST')]];
        yield 'basic without user' => [AuthType::Basic, ['password' => 'x'], []];
        yield 'bearer' => [AuthType::Bearer, ['token' => 'REDACTED-TEST'], ['Authorization' => 'Bearer REDACTED-TEST']];
        yield 'bearer without token' => [AuthType::Bearer, [], []];
        yield 'api key default header' => [AuthType::ApiKey, ['value' => 'REDACTED-TEST'], ['X-Api-Key' => 'REDACTED-TEST']];
        yield 'api key custom header' => [AuthType::ApiKey, ['header' => 'X-Key', 'value' => '{{user}}'], ['X-Key' => 'alice']];
        yield 'api key without value' => [AuthType::ApiKey, ['header' => 'X-Key'], []];
        yield 'custom' => [AuthType::Custom, ['token' => 'x'], []];
    }

    /**
     * @param array<string, string> $config
     * @param array<string, string> $expected
     */
    #[DataProvider('authentications')]
    public function testAuthenticationHeaders(AuthType $type, array $config, array $expected): void
    {
        $endpoint = $this->endpoint(self::BASE, '/me');
        $endpoint->getService()?->setAuthType($type)->setAuthConfig($config);

        $result = $this->executor()->execute($endpoint, null, null, [], [], ['user' => 'alice']);

        self::assertSame(['Content-Type' => 'application/json'] + $expected, $result->requestHeaders);
    }

    public function testGraphqlPostsTheQueryOrADefaultOne(): void
    {
        $endpoint = $this->endpoint(self::BASE, '/graphql');
        $endpoint->getService()?->setProtocol(ApiProtocol::Graphql);

        $default = $this->executor()->execute($endpoint);
        self::assertSame('POST', $default->requestMethod);
        self::assertSame('{"query": "{}"}', $default->requestBody);

        $custom = $this->executor()->execute($endpoint, null, '{"query": "{ me { id } }"}');
        self::assertSame('{"query": "{ me { id } }"}', $custom->requestBody);
    }

    public function testBlockedUrlWithoutHostIsLoggedWithUnknownHost(): void
    {
        $result = $this->executor()->execute($this->endpoint('', 'relative'));

        self::assertFalse($result->success);
        self::assertSame('/relative', $result->requestUrl);
        self::assertSame([], $this->calls);
        self::assertSame('error', $this->logs[0][0]);
        self::assertSame('(unknown)', $this->logs[0][2]['host']);
    }

    public function testRequestNeedingAnUnreadableSecretIsNeverSent(): void
    {
        $environment = new ApiEnvironment('Dev', 'dev');
        $environment->addVariable(new ApiEnvironmentVariable('host', self::BASE));
        $token = (new ApiEnvironmentVariable('token', 'nowo_as_enc_v1:payload'))->setSecret(true);
        $token->markUnreadable();
        $environment->addVariable($token);
        $endpoint = $this->endpoint('{{host}}', '/me');
        $endpoint->getService()?->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => '{{token}}']);

        $result = $this->executor()->execute($endpoint, $environment);

        self::assertSame([], $this->calls, 'Nothing is sent for a secret that cannot be decrypted.');
        self::assertFalse($result->success);
        self::assertSame(ApiExecutionResult::ERROR_SECRET_UNREADABLE, $result->errorCode);
        self::assertSame(['token'], $result->unreadableVariables);
        self::assertStringContainsString('{{token}}', (string) $result->errorMessage);
        self::assertStringNotContainsString('payload', (string) $result->errorMessage);
        self::assertSame('GET', $result->requestMethod);
        self::assertSame('execute_secret_unreadable', $this->logs[0][2]['action']);
    }

    public function testUnreadableSecretThatIsNotReferencedOrIsOverriddenDoesNotBlock(): void
    {
        $environment = new ApiEnvironment('Dev', 'dev');
        $token       = (new ApiEnvironmentVariable('token', 'nowo_as_enc_v1:payload'))->setSecret(true);
        $token->markUnreadable();
        $environment->addVariable($token);

        $result = $this->executor()->execute($this->endpoint(self::BASE, '/open'), $environment);
        self::assertTrue($result->success, 'Not referenced: the request runs.');

        $endpoint = $this->endpoint(self::BASE, '/me')->setHeaders(['Authorization' => 'Bearer {{token}}']);
        $result   = $this->executor()->execute($endpoint, $environment, variables: ['token' => 'typed-in-console']);
        self::assertTrue($result->success, 'A value typed in the console is used.');
        self::assertSame('Bearer typed-in-console', $result->requestHeaders['Authorization']);
    }

    public function testEmptyOrPlaceholderOverridesOfAnUnreadableSecretAreNoValue(): void
    {
        // EQ-I2: '' or the secret's own placeholder (spaced or not) never stands in for the unreadable value.
        $environment = new ApiEnvironment('Dev', 'dev');
        $token       = (new ApiEnvironmentVariable('token', 'nowo_as_enc_v1:payload'))->setSecret(true);
        $token->markUnreadable();
        $environment->addVariable($token);
        $endpoint = $this->endpoint(self::BASE, '/me')->setHeaders(['Authorization' => 'Bearer {{token}}']);

        foreach (['', '{{ token }}', '{{token}}', '{{other}}'] as $override) {
            $result = $this->executor()->execute($endpoint, $environment, variables: ['token' => $override]);
            self::assertSame(ApiExecutionResult::ERROR_SECRET_UNREADABLE, $result->errorCode, (string) json_encode($override));
        }
        self::assertSame([], $this->calls);
    }

    public function testReferencedVariablesFollowTheRequestPrecedence(): void
    {
        $endpoint = $this->endpoint('{{host}}', '/{{path}}')
            ->setQueryParams(['q' => '{{query}}'])
            ->setHeaders(['X-E' => '{{ endpoint_header }}'])
            ->setRequestBodyTemplate('{"a": "{{body}}"}');
        $service = $endpoint->getService();
        self::assertInstanceOf(ApiService::class, $service);
        $service->setDefaultHeaders(['X-S' => '{{service_header}}'])
            ->setAuthType(AuthType::Basic)
            ->setAuthConfig(['username' => '{{user}}', 'nested' => ['password' => '{{pass}}'], 'port' => 8080]);

        self::assertSame(
            ['host', 'path', 'body', 'query', 'service_header', 'endpoint_header', 'user', 'pass'],
            RequestExecutor::referencedVariables($endpoint, $service),
        );
        self::assertSame(
            ['host', 'path', 'override_body', 'override_query', 'override_header', 'user', 'pass'],
            RequestExecutor::referencedVariables($endpoint, $service, '{{override_body}}', ['X-O' => '{{override_header}}'], ['q' => '{{override_query}}'], true, true),
        );
    }

    private function endpoint(string $baseUrl, string $path): ApiEndpoint
    {
        $service = (new ApiService('Shop', 'shop'))->setBaseUrl($baseUrl);

        return (new ApiEndpoint('Call', 'call'))->setPath($path)->setService($service);
    }

    private function executor(?MockResponse $response = null): RequestExecutor
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->calls[] = [$method, $url, $options];

            return $response ?? new MockResponse('{"ok":true}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $logger = new class(function (string $level, string $message, array $context): void {
            $this->logs[] = [$level, $message, $context];
        }) extends AbstractLogger {

            /** @param Closure(string, string, array<string, mixed>): void $sink */
            public function __construct(private readonly Closure $sink)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->sink)((string) $level, (string) $message, $context);
            }
        };

        return new RequestExecutor($client, new VariableResolver(), new ExecutionUrlValidator([]), 5, $logger);
    }
}
