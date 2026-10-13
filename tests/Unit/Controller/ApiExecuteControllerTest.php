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
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiExecuteControllerTest extends TestCase
{
    public function testRejectsEnvironmentFromAnotherWorkspace(): void
    {
        $endpoint = $this->endpoint(new ApiWorkspace('Mine', 'mine'));
        $foreign  = (new ApiEnvironment('Prod', 'prod'))->setWorkspace(new ApiWorkspace('Other', 'other'));

        $client = new MockHttpClient(static function (): never {
            self::fail('Must not execute with an environment from another workspace.');
        });

        $response = $this->controller($endpoint, $foreign, $client)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('workspace', (string) $response->getContent());
    }

    public function testRejectsEnvironmentWhenEndpointHasNoWorkspace(): void
    {
        $endpoint = $this->endpoint(null);
        $env      = (new ApiEnvironment('Prod', 'prod'))->setWorkspace(new ApiWorkspace('Other', 'other'));

        $response = $this->controller($endpoint, $env, new MockHttpClient())->execute($this->request(), 1);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testExecutesWithEnvironmentFromSameWorkspace(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $endpoint  = $this->endpoint($workspace);
        $env       = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 200]));

        $response = $this->controller($endpoint, $env, $client)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testHistoryRedactsSecretsFromUrlAndCustomApiKeyHeader(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $endpoint  = $this->endpoint($workspace);
        $endpoint->setQueryParams(['token' => '{{sessionToken}}', 'lookup' => '{{lookupKey}}', 'page' => '2']);
        $endpoint->getService()?->setAuthType(AuthType::ApiKey)->setAuthConfig(['header' => 'X-Partner-Credential', 'value' => '{{partnerKey}}']);

        $env = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        $env->addVariable((new ApiEnvironmentVariable('sessionToken', 'tok-123456'))->setSecret(true));
        $env->addVariable((new ApiEnvironmentVariable('lookupKey', 'very-secret-lookup'))->setSecret(true));
        $env->addVariable((new ApiEnvironmentVariable('partnerKey', 'partner-abcdef'))->setSecret(true));

        $sentUrl = null;
        $client  = new MockHttpClient(static function (string $method, string $url) use (&$sentUrl): MockResponse {
            $sentUrl = $url;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $persisted = null;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $response = $this->controller($endpoint, $env, $client, $em)->execute($this->request(), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsString($sentUrl);
        self::assertStringContainsString('very-secret-lookup', $sentUrl, 'The real request must still carry the secret.');
        self::assertInstanceOf(ApiRequestHistory::class, $persisted);

        $storedUrl = $persisted->getRequestUrl();
        self::assertStringNotContainsString('tok-123456', $storedUrl);
        self::assertStringNotContainsString('very-secret-lookup', $storedUrl);
        self::assertStringContainsString('page=2', $storedUrl);
        self::assertSame('[REDACTED]', $persisted->getRequestHeaders()['X-Partner-Credential'] ?? null);
    }

    public function testHistoryMasksSecretValuesInCustomHeadersAndBody(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $endpoint  = $this->endpoint($workspace);
        $endpoint->setMethod(HttpMethod::Post);
        $endpoint->setHeaders(['X-Custom' => '{{customSecret}}', 'Accept' => 'application/json']);
        $endpoint->setRequestBodyTemplate('{"client_ref":"{{customSecret}}","pin":"{{overridden}}","page":2}');

        $env = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        $env->addVariable((new ApiEnvironmentVariable('customSecret', 'cs-998877'))->setSecret(true));
        $env->addVariable((new ApiEnvironmentVariable('overridden', 'stored-value'))->setSecret(true));

        $sent   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $persisted = null;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $request = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], '{"environment_id": 7, "variables": {"overridden": "typed-pin-4321"}}');
        $request->headers->set('X-CSRF-Token', 'valid');

        $response = $this->controller($endpoint, $env, $client, $em)->execute($request, 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('cs-998877', (string) ($sent['body'] ?? ''), 'The real request must still carry the secret.');
        self::assertInstanceOf(ApiRequestHistory::class, $persisted);

        $headers = $persisted->getRequestHeaders();
        self::assertSame('[REDACTED]', $headers['X-Custom'] ?? null);
        self::assertSame('application/json', $headers['Accept'] ?? null);

        $body = (string) $persisted->getRequestBody();
        self::assertStringNotContainsString('cs-998877', $body);
        self::assertStringNotContainsString('typed-pin-4321', $body);
        self::assertStringContainsString('"page":2', $body);
    }

    private function request(): Request
    {
        $request = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], '{"environment_id": 7}');
        $request->headers->set('X-CSRF-Token', 'valid');

        return $request;
    }

    private function endpoint(?ApiWorkspace $workspace): ApiEndpoint
    {
        $service = (new ApiService('Demo', 'demo'))
            ->setProtocol(ApiProtocol::Rest)
            ->setBaseUrl('https://93.184.215.14')
            ->setAuthType(AuthType::None)
            ->setWorkspace($workspace);

        return (new ApiEndpoint('Ping', 'ping'))
            ->setMethod(HttpMethod::Get)
            ->setPath('/ping')
            ->setService($service);
    }

    private function controller(ApiEndpoint $endpoint, ApiEnvironment $environment, MockHttpClient $client, ?EntityManagerInterface $entityManager = null): ApiExecuteController
    {
        $endpoints = $this->createMock(ApiEndpointRepository::class);
        $endpoints->method('find')->willReturn($endpoint);
        $environments = $this->createMock(ApiEnvironmentRepository::class);
        $environments->method('find')->with(7)->willReturn($environment);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);

        $controller = new ApiExecuteController(
            $endpoints,
            $environments,
            new RequestExecutor($client, new VariableResolver(), new ExecutionUrlValidator([]), 5),
            new HistorySanitizer(),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
        );
        $controller->setContainer($container);

        return $controller;
    }
}
