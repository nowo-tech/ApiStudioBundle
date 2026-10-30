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

use function json_decode;
use function json_encode;

/**
 * Request handling branches of ApiExecuteController: CSRF, unknown endpoint / environment, payload overrides.
 */
final class ApiExecuteControllerBranchesTest extends TestCase
{
    public function testInvalidCsrfTokenIsForbidden(): void
    {
        $response = $this->controller(null, null, new MockHttpClient(), csrfValid: false)->execute($this->request('{}'), 1);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testUnknownEndpointIsNotFound(): void
    {
        $response = $this->controller(null, null, new MockHttpClient())->execute($this->request('{}'), 1);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testNonJsonPayloadAndUnknownEnvironmentExecuteWithoutEnvironment(): void
    {
        $endpoint = $this->endpoint(new ApiWorkspace('Mine', 'mine'));
        $client   = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok', ['http_code' => 200]));

        $response = $this->controller($endpoint, null, $client)->execute($this->request('not json'), 1);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $response = $this->controller($endpoint, null, $client)->execute($this->request('{"environment_id": "7"}'), 1);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPayloadOverridesHeadersQueryMethodAndSecretVariables(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $endpoint  = $this->endpoint($workspace);
        $env       = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        $secret    = new ApiEnvironmentVariable('token', 'REDACTED-TEST-A');
        $secret->setSecret(true);
        $env->addVariable($secret);
        $empty = new ApiEnvironmentVariable('empty', '');
        $empty->setSecret(true);
        $env->addVariable($empty);
        $env->addVariable(new ApiEnvironmentVariable('plain', 'value'));

        $sent   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $persisted = null;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $payload = (string) json_encode([
            'environment_id' => 7,
            'method'         => 'post',
            'body'           => '{"a":1}',
            'headers'        => ['X-Token' => '{{token}}', '' => 'skip', 'X-Array' => ['no']],
            'query_params'   => ['page' => 2, '' => 'skip', 'q' => ['no']],
            'variables'      => ['token' => 'REDACTED-TEST-B', '' => 'skip', 'list' => ['no']],
        ]);

        $response = $this->controller($endpoint, $env, $client, $em)->execute($this->request($payload), 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('POST', $sent['method']);
        self::assertStringContainsString('page=2', $sent['url']);
        self::assertInstanceOf(ApiRequestHistory::class, $persisted);
        self::assertStringNotContainsString('REDACTED-TEST-B', (string) json_encode($persisted->getRequestHeaders()));
        self::assertIsArray(json_decode((string) $response->getContent(), true));
    }

    private function request(string $content): Request
    {
        $request = Request::create('/api-studio/execute/1', 'POST', [], [], [], [], $content);
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

    private function controller(?ApiEndpoint $endpoint, ?ApiEnvironment $environment, MockHttpClient $client, ?EntityManagerInterface $entityManager = null, bool $csrfValid = true): ApiExecuteController
    {
        $endpoints = $this->createMock(ApiEndpointRepository::class);
        $endpoints->method('find')->willReturn($endpoint);
        $environments = $this->createMock(ApiEnvironmentRepository::class);
        $environments->method('find')->willReturn($environment);

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

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
