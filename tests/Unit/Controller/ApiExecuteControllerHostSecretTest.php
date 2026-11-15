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
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestHostSecretSource;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
        $container->set('security.csrf.token_manager', $csrf);
        if ($withResolver) {
            $container->set(HostSecretResolver::class, new HostSecretResolver($source, $logger));
        }
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
