<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_repeat;

/**
 * JSON endpoints used by the request console: saved request / response examples, environment variable sync and
 * endpoint documentation. Tokens come from the console page (same session).
 */
final class StudioJsonEndpointsTest extends StudioWebTestCase
{
    /** @var array{examples: string, envSync: string, doc: string} */
    private array $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $crawler      = $this->client->request('GET', $this->endpointUrl());
        $tester       = $crawler->filter('#api-studio-tester');
        $this->tokens = [
            'examples' => (string) $tester->attr('data-examples-csrf'),
            'envSync'  => (string) $tester->attr('data-env-sync-csrf'),
            'doc'      => (string) $crawler->filter('#endpoint-doc-panel')->attr('data-csrf'),
        ];
    }

    public function testRequestExampleSaveAndDelete(): void
    {
        $data = $this->post($this->endpointUrl('/examples/request', $this->ids['endpoint']), $this->tokens['examples'], [
            'name'         => 'Second page',
            'body'         => '{"page": 2}',
            'headers'      => ['Accept' => 'application/json', '' => 'skip', 'X-List' => ['no']],
            'query_params' => ['page' => 2],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Second page', $data['name']);
        self::assertSame(['Accept' => 'application/json', 'X-List' => ''], $data['headers']);
        self::assertSame(['page' => '2'], $data['query_params']);

        $this->post($this->exampleUrl('request', (int) $data['id']), $this->tokens['examples']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(ApiRequestExample::class)->find($data['id']));

        $this->post($this->exampleUrl('request', 999999), $this->tokens['examples']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testResponseExampleSaveAndDelete(): void
    {
        $data = $this->post($this->endpointUrl('/examples/response', $this->ids['endpoint']), $this->tokens['examples'], [
            'name'             => 'Created',
            'status_code'      => '201',
            'response_body'    => '{"id": 1}',
            'response_headers' => 'not a map',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(201, $data['status_code']);
        self::assertSame([], $data['response_headers']);

        $default = $this->post($this->endpointUrl('/examples/response', $this->ids['endpoint']), $this->tokens['examples'], ['name' => 'Default']);
        self::assertSame(200, $default['status_code']);

        $this->post($this->exampleUrl('response', (int) $data['id']), $this->tokens['examples']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(ApiResponseExample::class)->find($data['id']));

        $this->post($this->exampleUrl('response', 999999), $this->tokens['examples']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExamplesNeedANameAndAValidToken(): void
    {
        foreach (['request', 'response'] as $type) {
            $this->post($this->endpointUrl('/examples/' . $type, $this->ids['endpoint']), $this->tokens['examples'], ['name' => '  ']);
            self::assertResponseStatusCodeSame(400);

            $this->client->request('POST', $this->endpointUrl('/examples/' . $type, $this->ids['endpoint']), [], [], ['HTTP_X_CSRF_TOKEN' => 'forged'], 'not json');
            self::assertResponseStatusCodeSame(403);

            $this->post($this->exampleUrl($type, 1), 'forged');
            self::assertResponseStatusCodeSame(403);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignContexts(): iterable
    {
        yield 'unknown workspace' => ['workspace'];
        yield 'service of another workspace' => ['service'];
        yield 'endpoint of another service' => ['endpoint'];
    }

    #[DataProvider('foreignContexts')]
    public function testExamplesOfAForeignContextAreNotFound(string $case): void
    {
        $url = match ($case) {
            'workspace' => '/api-studio/workspaces/999999/services/1/endpoints/1/examples/request',
            'service'   => $this->workspaceUrl(sprintf('/services/%d/endpoints/%d/examples/request', $this->ids['service'], $this->ids['endpoint']), $this->ids['other']),
            default     => $this->endpointUrl('/examples/request', $this->foreignEndpointId()),
        };

        $this->expectException(NotFoundHttpException::class);
        $this->post($url, $this->tokens['examples'], ['name' => 'x']);
    }

    public function testExampleOfAnotherEndpointIsNotDeleted(): void
    {
        $example = $this->post($this->endpointUrl('/examples/request', $this->ids['endpoint']), $this->tokens['examples'], ['name' => 'Mine']);
        $sibling = new ApiEndpoint('Sibling', 'sibling');
        $service = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        $service->addEndpoint($sibling);
        $this->entityManager()->persist($sibling);
        $this->entityManager()->flush();

        foreach (['request', 'response'] as $type) {
            $this->client->request('POST', $this->endpointUrl(sprintf('/examples/%s/%d/delete', $type, $example['id']), (int) $sibling->getId()), [], [], ['HTTP_X_CSRF_TOKEN' => $this->tokens['examples']]);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testEnvironmentVariablesSync(): void
    {
        $url  = $this->workspaceUrl(sprintf('/environments/%d/variables/sync', $this->ids['environment']));
        $data = $this->post($url, $this->tokens['envSync'], ['variables' => [
            'brand_new'  => 'one',
            '  '         => 'blank key',
            '9 invalid!' => 'invalid key',
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['created']);

        $data = $this->post($url, $this->tokens['envSync'], ['variables' => ['brand_new' => 'two']]);
        self::assertSame(['success' => true, 'created' => 0, 'updated' => 1], $data);

        $this->post($url, $this->tokens['envSync'], ['nothing' => true]);
        self::assertResponseStatusCodeSame(400);

        $this->post($url, 'forged', ['variables' => []]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignEnvironments(): iterable
    {
        yield 'unknown workspace' => ['/api-studio/workspaces/999999/environments/1/variables/sync'];
        yield 'environment of another workspace' => ['other'];
    }

    #[DataProvider('foreignEnvironments')]
    public function testEnvironmentSyncOfAForeignEnvironmentIsNotFound(string $url): void
    {
        if ($url === 'other') {
            $url = $this->workspaceUrl(sprintf('/environments/%d/variables/sync', $this->ids['environment']), $this->ids['other']);
        }

        $this->expectException(NotFoundHttpException::class);
        $this->post($url, $this->tokens['envSync'], ['variables' => []]);
    }

    public function testDocumentationSave(): void
    {
        $endpoint = new ApiEndpoint('Bare', 'bare');
        $service  = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        $service->addEndpoint($endpoint);
        $this->entityManager()->persist($endpoint);
        $this->entityManager()->flush();
        $url = $this->endpointUrl('/documentation', (int) $endpoint->getId());

        $data = $this->post($url, $this->tokens['doc'], ['locale' => 'es', 'title' => ' Título ', 'description' => '']);
        self::assertResponseIsSuccessful();
        self::assertSame(['locale' => 'es', 'title' => 'Título', 'display_title' => 'Título', 'description' => ''], $data);

        // No locale in the payload: the session UI locale; empty title falls back to the endpoint name.
        $data = $this->post($url, $this->tokens['doc'], ['description' => 'Liveness probe']);
        self::assertSame('Bare', $data['display_title']);
        self::assertSame('Liveness probe', $data['description']);

        $this->client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => $this->tokens['doc']], 'not json');
        self::assertResponseIsSuccessful();

        $this->post($url, $this->tokens['doc'], ['locale' => 'xx']);
        self::assertResponseStatusCodeSame(400);

        $this->post($url, 'forged', ['locale' => 'en']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOverlongNamesAndTitlesAreRejectedNotStored(): void
    {
        // BW audit I4: VARCHAR overflow answers 400, never a DB error.
        $data = $this->post($this->endpointUrl('/examples/request', $this->ids['endpoint']), $this->tokens['examples'], ['name' => str_repeat('n', 129)]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('128 characters', (string) ($data['error'] ?? ''));

        $this->post($this->endpointUrl('/examples/response', $this->ids['endpoint']), $this->tokens['examples'], ['name' => str_repeat('n', 129)]);
        self::assertResponseStatusCodeSame(400);

        $this->post($this->endpointUrl('/examples/request', $this->ids['endpoint']), $this->tokens['examples'], ['name' => str_repeat('n', 128)]);
        self::assertResponseIsSuccessful();

        $data = $this->post($this->endpointUrl('/documentation'), $this->tokens['doc'], ['locale' => 'en', 'title' => str_repeat('t', 256)]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('255 characters', (string) ($data['error'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $url, string $token, ?array $payload = null): array
    {
        $this->client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], $payload === null ? '' : (string) json_encode($payload));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function exampleUrl(string $type, int $exampleId): string
    {
        return $this->endpointUrl(sprintf('/examples/%s/%d/delete', $type, $exampleId), $this->ids['endpoint']);
    }

    private function foreignEndpointId(): int
    {
        foreach ($this->entityManager()->getRepository(ApiEndpoint::class)->findAll() as $endpoint) {
            if ($endpoint->getService()?->getId() !== $this->ids['service']) {
                return (int) $endpoint->getId();
            }
        }

        self::fail('The demo seed has endpoints in several services.');
    }

    public function testSeedHasEnvironments(): void
    {
        self::assertInstanceOf(ApiEnvironment::class, $this->entityManager()->getRepository(ApiEnvironment::class)->find($this->ids['environment']));
    }
}
