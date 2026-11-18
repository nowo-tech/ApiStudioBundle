<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\ApiExecuteController;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestHostSecretSource;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function base64_encode;
use function htmlspecialchars;
use function json_decode;
use function json_encode;
use function rawurlencode;

/**
 * 1.13 host secret source in the execute action: masking of the resolved value outside the outbound request, fail
 * closed, and the 1.12 behaviour without a source.
 */
final class ApiExecuteControllerHostSecretTest extends TestCase
{
    private const REFERENCE = '{{secret:vault/payments}}';

    public function testTransportErrorQuotingTheValueIsMaskedInTheResponseHistoryAndLogs(): void
    {
        $value            = 'hv-REDACTED-TEST-transport';
        [$endpoint, $env] = $this->fixture();
        $endpoint->setQueryParams(['k' => '{{hostToken}}']);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => $value];
        $logger          = new InMemoryLogger();
        $client          = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('', ['error' => 'Could not reach "' . $url . '" with ' . $value]));

        $persisted = null;
        $response  = $this->controller($endpoint, $env, $client, $source, $logger, $persisted)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringNotContainsString($value, $content);
        self::assertStringNotContainsString(rawurlencode($value), $content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertStringContainsString('[REDACTED]', (string) $data['error_message'], 'the transport error is kept, masked');
        self::assertInstanceOf(ApiRequestHistory::class, $persisted);
        self::assertStringNotContainsString($value, (string) $persisted->getErrorMessage());
        self::assertStringNotContainsString(rawurlencode($value), $persisted->getRequestUrl());
        self::assertNotSame('', $logger->text());
        self::assertStringNotContainsString($value, $logger->text(), 'the executor logs through the masking logger');
        self::assertStringNotContainsString(rawurlencode($value), $logger->text());
    }

    public function testAHostSecretUsedAsTheHostIsLeftOutOfTheEvent(): void
    {
        [$endpoint, $env] = $this->fixture();
        $endpoint->getService()?->setBaseUrl('https://{{hostToken}}');
        $endpoint->setHeaders([]);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => '93.184.215.14'];
        $events          = [];
        $dispatcher      = new EventDispatcher();
        $dispatcher->addListener(ApiRequestExecutedEvent::class, static function (ApiRequestExecutedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $persisted = null;
        $response  = $this->controller($endpoint, $env, new MockHttpClient(new MockResponse('{}')), $source, null, $persisted, $dispatcher)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('93.184.215.14', (string) $response->getContent());
        self::assertCount(1, $events);
        self::assertNull($events[0]->host);
    }

    public function testAnUnresolvedReferenceTheRequestDoesNotUseDoesNotStopIt(): void
    {
        [$endpoint, $env] = $this->fixture();
        $endpoint->setHeaders([]);
        $sent   = false;
        $client = new MockHttpClient(static function () use (&$sent): MockResponse {
            $sent = true;

            return new MockResponse('{}');
        });
        $persisted = null;

        $response = $this->controller($endpoint, $env, $client, new TestHostSecretSource(), null, $persisted)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($sent);
        self::assertArrayNotHasKey('error_code', (array) json_decode((string) $response->getContent(), true));
    }

    public function testAnUnresolvedReferenceTheRequestNeedsIsNeverSent(): void
    {
        [$endpoint, $env] = $this->fixture();
        $client           = new MockHttpClient(static function (): never {
            self::fail('Must not send a request that needs an unresolved host secret.');
        });
        $persisted = null;

        $response = $this->controller($endpoint, $env, $client, new TestHostSecretSource(), null, $persisted)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('secret_unresolved', $data['error_code']);
        self::assertSame(['hostToken'], $data['unresolved_variables']);
        self::assertSame([], $data['unreadable_variables']);
        self::assertInstanceOf(ApiRequestHistory::class, $persisted);
        self::assertFalse($persisted->isSuccess());
    }

    public function testAConsoleOverrideIsSentAsTypedAndNeverResolved(): void
    {
        [$endpoint, $env] = $this->fixture();
        $source           = new TestHostSecretSource();
        $source->secrets  = ['vault/payments' => 'hv-REDACTED-TEST-unused'];
        $sent             = [];
        $client           = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['headers'] ?? [];

            return new MockResponse('{}');
        });
        $persisted = null;
        $request   = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], (string) json_encode(['environment_id' => 7, 'variables' => ['hostToken' => '{{secret:vault/other}}']]));
        $request->headers->set('X-CSRF-Token', 'valid');

        $this->controller($endpoint, $env, $client, $source, null, $persisted)->execute($request, 1);

        self::assertSame([], $source->calls);
        self::assertContains('X-Host-Token: {{secret:vault/other}}', $sent);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function consoleTemplates(): iterable
    {
        yield 'query override' => [['query_params' => ['q' => '{{hostToken}}']], true];
        yield 'header override' => [['headers' => ['X-Host-Token' => '{{hostToken}}', 'X-Echo' => 'Bearer {{ hostToken }}']], true];
        yield 'body override' => [['body' => '{"echo":"{{hostToken}}"}'], true];
        yield 'stored header sent back unchanged' => [['headers' => ['X-Host-Token' => '{{hostToken}}']], false];
        yield 'stored body and query sent back unchanged' => [['body' => '', 'query_params' => []], false];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('consoleTemplates')]
    public function testARunnerCannotPlaceAHostSecretInATemplateOfTheirOwn(array $payload, bool $refused): void
    {
        // QE-B-M2: without workspace.edit, host secrets stay where an editor put them.
        [$endpoint, $env] = $this->fixture();
        $source           = new TestHostSecretSource();
        $source->secrets  = ['vault/payments' => 'hv-REDACTED-TEST-placed'];
        $sent             = false;
        $client           = new MockHttpClient(static function () use (&$sent): MockResponse {
            $sent = true;

            return new MockResponse('{}');
        });
        $checker         = new TestPermissionChecker();
        $checker->denied = [ApiStudioAction::WorkspaceEdit];
        $request         = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], (string) json_encode(['environment_id' => 7, 'variables' => ['hostToken' => '{{hostToken}}']] + $payload));
        $request->headers->set('X-CSRF-Token', 'valid');
        $persisted = null;

        $response = $this->controller($endpoint, $env, $client, $source, null, $persisted, null, true, new ApiStudioAuthorization($checker))->execute($request, 1);

        if (!$refused) {
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            self::assertTrue($sent);

            return;
        }
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('override_not_allowed', $data['error']);
        self::assertSame(['hostToken'], $data['variables']);
        self::assertFalse($sent);
        self::assertSame([], $source->calls, 'the host is not even asked');
        self::assertNull($persisted);

        // An editor may.
        $response = $this->controller($endpoint, $env, $client, $source, null, $persisted)->execute($request, 1);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertCount(1, self::callsOf($source));
    }

    public function testThePortSeesTheTargetOriginAndBasicAuthEchoesAreMasked(): void
    {
        // QE-B-M1 / QE-B-L1.
        $value            = 'hv-REDACTED-TEST-basic';
        [$endpoint, $env] = $this->fixture();
        $endpoint->getService()?->setAuthType(AuthType::Basic)->setAuthConfig(['username' => 'svc-user', 'password' => '{{hostToken}}']);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => $value];
        $client          = new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => new MockResponse((string) json_encode(['headers' => $options['headers'] ?? [], 'html' => htmlspecialchars($value), 'b64' => base64_encode($value)])));
        $persisted       = null;

        $response = $this->controller($endpoint, $env, $client, $source, null, $persisted)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('https://93.184.215.14', $source->calls[0]->targetOrigin);
        $content = (string) $response->getContent();
        foreach ([$value, base64_encode($value), base64_encode('svc-user:' . $value)] as $form) {
            self::assertStringNotContainsString($form, $content);
            self::assertInstanceOf(ApiRequestHistory::class, $persisted);
            self::assertStringNotContainsString($form, (string) $persisted->getResponseBody());
        }
    }

    public function testABaseUrlUsingAHostSecretGivesNoTargetOrigin(): void
    {
        [$endpoint, $env] = $this->fixture();
        $endpoint->getService()?->setBaseUrl('https://{{hostToken}}');
        $endpoint->setHeaders([]);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => '93.184.215.14'];
        $persisted       = null;

        $this->controller($endpoint, $env, new MockHttpClient(new MockResponse('{}')), $source, null, $persisted)->execute($this->request(), 1);

        self::assertNull($source->calls[0]->targetOrigin);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, bool, int}>
     */
    public static function hostHeaders(): iterable
    {
        // [stored endpoint headers, console headers, runner can edit, expected status]
        yield 'runner Host override (audit PoC, inverted)' => [['X-Host-Token' => '{{hostToken}}'], ['X-Host-Token' => '{{hostToken}}', 'Host' => 'attacker-app.shared-front.example'], false, 422];
        yield 'editor Host override' => [['X-Host-Token' => '{{hostToken}}'], ['X-Host-Token' => '{{hostToken}}', 'host' => 'attacker-app.shared-front.example'], true, 422];
        yield 'stored Host header' => [['X-Host-Token' => '{{hostToken}}', 'Host' => 'attacker-app.shared-front.example'], [], true, 422];
        yield 'Host with userinfo' => [['X-Host-Token' => '{{hostToken}}', 'Host' => 'x@93.184.215.14'], [], true, 422];
        yield 'Host with a backslash' => [['X-Host-Token' => '{{hostToken}}', 'Host' => '93.184.215.14\\evil.example'], [], true, 422];
        yield 'Host from a variable resolving elsewhere' => [['X-Host-Token' => '{{hostToken}}', 'Host' => '{{hostName}}'], [], true, 422];
        yield 'Host equal to the authority' => [['X-Host-Token' => '{{hostToken}}', 'Host' => '93.184.215.14'], [], true, 200];
        yield 'Host with the default port' => [['X-Host-Token' => '{{hostToken}}', 'HOST' => '93.184.215.14:443'], [], true, 200];
    }

    /**
     * @param array<string, string> $stored
     * @param array<string, string> $console
     */
    #[DataProvider('hostHeaders')]
    public function testAHostHeaderMustMatchTheUrlAuthorityWhenHostSecretsAreSent(array $stored, array $console, bool $canEdit, int $status): void
    {
        // QE-D-M1: a shared front end routing by Host would hand the secret to another app behind the approved origin.
        [$endpoint, $env] = $this->fixture();
        $endpoint->setHeaders($stored);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => 'hv-REDACTED-TEST-host'];
        $sent            = false;
        $client          = new MockHttpClient(static function () use (&$sent): MockResponse {
            $sent = true;

            return new MockResponse('{}');
        });
        $checker         = new TestPermissionChecker();
        $checker->denied = $canEdit ? [] : [ApiStudioAction::WorkspaceEdit];
        $payload         = ['environment_id' => 7, 'variables' => ['hostToken' => '{{hostToken}}']];
        if ($console !== []) {
            $payload['headers'] = $console;
        }
        $request = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], (string) json_encode($payload));
        $request->headers->set('X-CSRF-Token', 'valid');
        $persisted = null;

        $response = $this->controller($endpoint, $env, $client, $source, null, $persisted, null, true, new ApiStudioAuthorization($checker))->execute($request, 1);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($status === 200, $sent);
        if ($status === 422) {
            $data = json_decode((string) $response->getContent(), true);
            self::assertIsArray($data);
            self::assertSame('secret_target_changed', $data['error_code']);
        }
    }

    public function testAHostHeaderStaysFreeWithoutHostSecrets(): void
    {
        [$endpoint, $env] = $this->fixture();
        $endpoint->setHeaders(['Host' => 'other-app.shared-front.example']);
        $persisted = null;

        $response = $this->controller($endpoint, $env, new MockHttpClient(new MockResponse('{}')), null, null, $persisted)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function bodies(): iterable
    {
        // [stored body, body sent by the console, expected status for a runner]
        yield 'CRLF stored, LF back (imported Postman body)' => ["{\r\n  \"k\": \"{{hostToken}}\"\r\n}", "{\n  \"k\": \"{{hostToken}}\"\n}", 200];
        yield 'leading newline dropped by the textarea' => ["\n{\"k\":\"{{hostToken}}\"}", '{"k":"{{hostToken}}"}', 200];
        yield 'trailing newline' => ['{"k":"{{hostToken}}"}' . "\r\n", '{"k":"{{hostToken}}"}', 200];
        yield 'reformatted' => ['{"k":"{{hostToken}}"}', '{ "k": "{{hostToken}}" }', 422];
        yield 'two trailing newlines' => ['{"k":"{{hostToken}}"}' . "\n\n", '{"k":"{{hostToken}}"}', 422];
    }

    #[DataProvider('bodies')]
    public function testAnUnchangedBodyIsComparedWithoutLineEndingNoise(string $stored, string $sent, int $status): void
    {
        // QE-D-L2: strict otherwise.
        [$endpoint, $env] = $this->fixture();
        $endpoint->setHeaders([])->setRequestBodyTemplate($stored);
        $source          = new TestHostSecretSource();
        $source->secrets = ['vault/payments' => 'hv-REDACTED-TEST-crlf'];
        $checker         = new TestPermissionChecker();
        $checker->denied = [ApiStudioAction::WorkspaceEdit];
        $request         = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], (string) json_encode(['environment_id' => 7, 'variables' => ['hostToken' => '{{hostToken}}'], 'body' => $sent]));
        $request->headers->set('X-CSRF-Token', 'valid');
        $persisted = null;

        $response = $this->controller($endpoint, $env, new MockHttpClient(new MockResponse('{}')), $source, null, $persisted, null, true, new ApiStudioAuthorization($checker))->execute($request, 1);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testWithoutASourceTheResponseIsByteIdenticalAndTheReferenceIsAPlainValue(): void
    {
        $responses = [];
        $outbound  = [];
        foreach (['no resolver service' => false, 'resolver without a source' => true] as $case => $withResolver) {
            [$endpoint, $env] = $this->fixture();
            $client           = new MockHttpClient(static function (string $method, string $url, array $options) use (&$outbound, $case): MockResponse {
                $outbound[$case] = $options['headers'] ?? [];

                return new MockResponse('{"echo":"' . self::REFERENCE . '"}', ['http_code' => 200]);
            });
            $persisted        = null;
            $response         = $this->controller($endpoint, $env, $client, null, null, $persisted, null, $withResolver)->execute($this->request(), 1);
            $responses[$case] = (string) $response->getContent();
        }

        self::assertSame($responses['no resolver service'], $responses['resolver without a source']);
        self::assertContains('X-Host-Token: ' . self::REFERENCE, $outbound['resolver without a source'], 'the stored value is sent as is');
        self::assertStringNotContainsString('error_code', $responses['no resolver service']);
    }

    /**
     * @return list<ApiStudioSecretRef>
     */
    private static function callsOf(TestHostSecretSource $source): array
    {
        return $source->calls;
    }

    private function request(): Request
    {
        $request = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], '{"environment_id": 7, "variables": {"hostToken": "{{hostToken}}"}}');
        $request->headers->set('X-CSRF-Token', 'valid');

        return $request;
    }

    /**
     * @return array{ApiEndpoint, ApiEnvironment}
     */
    private function fixture(): array
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $service   = (new ApiService('Demo', 'demo'))
            ->setProtocol(ApiProtocol::Rest)
            ->setBaseUrl('https://93.184.215.14')
            ->setAuthType(AuthType::None)
            ->setWorkspace($workspace);
        $endpoint = (new ApiEndpoint('Ping', 'ping'))
            ->setMethod(HttpMethod::Get)
            ->setPath('/ping')
            ->setHeaders(['X-Host-Token' => '{{hostToken}}'])
            ->setService($service);
        $env = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        $env->addVariable((new ApiEnvironmentVariable('hostToken', self::REFERENCE))->setSecret(true));
        $env->addVariable(new ApiEnvironmentVariable('hostName', 'attacker-app.shared-front.example'));

        return [$endpoint, $env];
    }

    private function controller(
        ApiEndpoint $endpoint,
        ApiEnvironment $environment,
        MockHttpClient $client,
        ?TestHostSecretSource $source,
        ?InMemoryLogger $logger,
        ?ApiRequestHistory &$persisted,
        ?EventDispatcher $dispatcher = null,
        bool $withResolver = true,
        ?ApiStudioAuthorization $authorization = null,
    ): ApiExecuteController {
        $endpoints = $this->createMock(ApiEndpointRepository::class);
        $endpoints->method('find')->willReturn($endpoint);
        $environments = $this->createMock(ApiEnvironmentRepository::class);
        $environments->method('find')->with(7)->willReturn($environment);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof ApiRequestHistory) {
                $persisted = $entity;
            }
        });

        $container = new Container();
        $container->set(ApiStudioAccessPolicy::class, new ApiStudioAccessPolicy());
        $container->set('security.csrf.token_manager', $csrf);
        if ($withResolver) {
            $container->set(HostSecretResolver::class, new HostSecretResolver($source, $logger));
        }
        // 1.16 (SY-L2): the trait fails closed; the default authorization grants everything.
        $container->set(ApiStudioAuthorization::class, $authorization ?? new ApiStudioAuthorization());
        if ($dispatcher instanceof EventDispatcher) {
            $container->set('event_dispatcher', $dispatcher);
        }

        $controller = new ApiExecuteController(
            $endpoints,
            $environments,
            new RequestExecutor($client, new VariableResolver(), new ExecutionUrlValidator([]), 5, $logger),
            new HistorySanitizer(),
            $em,
        );
        $controller->setContainer($container);

        return $controller;
    }
}
