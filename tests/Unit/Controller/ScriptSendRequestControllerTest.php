<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Nowo\ApiStudioBundle\Controller\ScriptSendRequestController;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Script\ScriptRequestSender;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function json_decode;

/**
 * Spec 003 (1.15): pm.sendRequest endpoint in sandbox mode (token, endpoint, payload, guarded send).
 */
final class ScriptSendRequestControllerTest extends TestCase
{
    public function testSendsAndAnswersTheResponse(): void
    {
        $response = $this->controller(true)->send($this->request('{"url":"https://93.184.215.14/a","method":"GET"}'), 3);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(204, json_decode((string) $response->getContent(), true)['response']['code']);
    }

    public function testRefusals(): void
    {
        self::assertSame(404, (new ScriptSendRequestController($this->endpoints(null), $this->sender(), null, 'inline'))->send($this->request('{}'), 3)->getStatusCode());
        self::assertSame(403, $this->controller(false)->send($this->request('{}'), 3)->getStatusCode());
        self::assertSame(404, $this->controller(true, null)->send($this->request('{}'), 3)->getStatusCode());
        self::assertSame(400, $this->controller(true)->send($this->request('not json'), 3)->getStatusCode());
        $guarded = $this->controller(true)->send($this->request('{"url":"http://127.0.0.1/"}'), 3);
        self::assertSame(422, $guarded->getStatusCode());
        self::assertStringContainsString('not allowed', (string) $guarded->getContent());
    }

    private function controller(bool $validToken, ?ApiEndpoint $endpoint = new ApiEndpoint('E', 'e')): ScriptSendRequestController
    {
        if ($endpoint instanceof ApiEndpoint) {
            $workspace = new ApiWorkspace('W', 'w');
            $service   = new ApiService('S', 's');
            $workspace->addService($service);
            $endpoint->setService($service);
        }
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($validToken);
        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new ScriptSendRequestController($this->endpoints($endpoint), $this->sender(), null, 'sandbox');
        $controller->setContainer($container);

        return $controller;
    }

    private function endpoints(?ApiEndpoint $endpoint): ApiEndpointRepository
    {
        $repository = $this->createStub(ApiEndpointRepository::class);
        $repository->method('find')->willReturn($endpoint);

        return $repository;
    }

    private function sender(): ScriptRequestSender
    {
        return new ScriptRequestSender(new MockHttpClient(new MockResponse('', ['http_code' => 204])), new ExecutionUrlValidator([]));
    }

    private function request(string $content): Request
    {
        return Request::create('/scripts/send-request/3', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 't'], content: $content);
    }
}
