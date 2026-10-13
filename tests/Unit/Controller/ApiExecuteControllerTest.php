<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\ApiExecuteController;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
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

    private function controller(ApiEndpoint $endpoint, ApiEnvironment $environment, MockHttpClient $client): ApiExecuteController
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
            $this->createMock(EntityManagerInterface::class),
        );
        $controller->setContainer($container);

        return $controller;
    }
}
