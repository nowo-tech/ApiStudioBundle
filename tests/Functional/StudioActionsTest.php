<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function sprintf;

/**
 * Create / edit / delete flows of the workspace, service, environment and endpoint pages: redirects, translated
 * flashes, CSRF failures and 404s for foreign ids.
 */
final class StudioActionsTest extends StudioWebTestCase
{
    public function testWorkspaceCreateAndEdit(): void
    {
        $crawler = $this->client->request('GET', '/api-studio/workspaces/new');
        $form    = $crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Payments',
            'api_workspace_form[slug]' => 'payments',
        ]);
        $this->client->submit($form);

        $workspace = $this->entityManager()->getRepository(ApiWorkspace::class)->findOneBy(['slug' => 'payments']);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        self::assertResponseRedirects($this->workspaceUrl('', (int) $workspace->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Created successfully.', (string) $this->client->getResponse()->getContent());
        self::assertNotEmpty($workspace->getEnvironments(), 'default environments are created');

        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit', (int) $workspace->getId()));
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form(['api_workspace_form[name]' => 'Payments v2']));
        self::assertResponseRedirects($this->workspaceUrl('', (int) $workspace->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Updated successfully.', (string) $this->client->getResponse()->getContent());
    }

    public function testServiceCreateEditAndDelete(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/services/new'));
        $this->client->submit($crawler->filter('form[name="api_service_form"]')->form([
            'api_service_form[name]'    => 'Orders API',
            'api_service_form[slug]'    => 'orders',
            'api_service_form[baseUrl]' => 'https://orders.example.test',
        ]));
        $service = $this->entityManager()->getRepository(ApiService::class)->findOneBy(['slug' => 'orders']);
        self::assertInstanceOf(ApiService::class, $service);
        $id = (int) $service->getId();
        self::assertResponseRedirects($this->serviceUrl('', $id));

        $crawler = $this->client->request('GET', $this->serviceUrl('/edit', $id));
        $this->client->submit($crawler->filter('form[name="api_service_form"]')->form(['api_service_form[name]' => 'Orders API v2']));
        self::assertResponseRedirects($this->serviceUrl('', $id));

        // The delete form carries the typed name: the service is removed.
        $crawler = $this->client->request('GET', $this->serviceUrl('/edit', $id));
        $this->client->submit($crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form());
        self::assertResponseRedirects($this->workspaceUrl());
        $this->client->followRedirect();
        self::assertStringContainsString('Deleted successfully.', (string) $this->client->getResponse()->getContent());
    }

    public function testServiceDeleteWithWrongNameKeepsTheService(): void
    {
        $crawler      = $this->client->request('GET', $this->serviceUrl('/edit'));
        $form         = $crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form();
        $confirmation = $form['confirmation'];
        self::assertInstanceOf(InputFormField::class, $confirmation);
        $confirmation->setValue('not it');
        $this->client->submit($form);

        self::assertResponseRedirects($this->workspaceUrl());
        $this->client->followRedirect();
        self::assertStringContainsString('Confirmation name does not match.', (string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(ApiService::class, $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']));
    }

    public function testEnvironmentCreateAsDefaultEditAndDelete(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/environments/new'));
        $form    = $crawler->filter('form[name="api_environment_form"]')->form([
            'api_environment_form[name]' => 'Staging',
            'api_environment_form[slug]' => 'staging',
        ]);
        $isDefault = $form['api_environment_form[isDefault]'];
        self::assertInstanceOf(ChoiceFormField::class, $isDefault);
        $isDefault->tick();
        $this->client->submit($form);

        $environment = $this->entityManager()->getRepository(ApiEnvironment::class)->findOneBy(['slug' => 'staging']);
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        $id = (int) $environment->getId();
        self::assertResponseRedirects($this->environmentUrl('', $id));
        self::assertTrue($environment->isDefault());
        $defaults = $this->entityManager()->getRepository(ApiEnvironment::class)->findBy(['workspace' => $this->ids['workspace'], 'isDefault' => true]);
        self::assertCount(1, $defaults, 'only the new environment stays default');

        // Edit another environment into the default one.
        $crawler   = $this->client->request('GET', $this->environmentUrl('/edit'));
        $form      = $crawler->filter('form[name="api_environment_form"]')->form();
        $isDefault = $form['api_environment_form[isDefault]'];
        self::assertInstanceOf(ChoiceFormField::class, $isDefault);
        $isDefault->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($this->environmentUrl());
        $this->entityManager()->clear();
        $staging = $this->entityManager()->getRepository(ApiEnvironment::class)->find($id);
        self::assertInstanceOf(ApiEnvironment::class, $staging);
        self::assertFalse($staging->isDefault());

        $crawler = $this->client->request('GET', $this->environmentUrl('/edit', $id));
        $this->client->submit($crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form());
        self::assertResponseRedirects($this->workspaceUrl('/environments'));
        self::assertNull($this->entityManager()->getRepository(ApiEnvironment::class)->find($id));
    }

    public function testEndpointCreateAndEdit(): void
    {
        $crawler = $this->client->request('GET', $this->serviceUrl('/endpoints/new'));
        $this->client->submit($crawler->filter('form[name="api_endpoint_form"]')->form([
            'api_endpoint_form[name]' => 'Health',
            'api_endpoint_form[slug]' => 'health',
            'api_endpoint_form[path]' => '/health',
        ]));
        $endpoint = $this->entityManager()->getRepository(ApiEndpoint::class)->findOneBy(['slug' => 'health']);
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);
        self::assertResponseRedirects($this->endpointUrl('', (int) $endpoint->getId()));

        $crawler = $this->client->request('GET', $this->endpointUrl('/edit', (int) $endpoint->getId()));
        $this->client->submit($crawler->filter('form[name="api_endpoint_form"]')->form(['api_endpoint_form[name]' => 'Health check']));
        self::assertResponseRedirects($this->endpointUrl('', (int) $endpoint->getId()));
        $this->client->followRedirect();
        self::assertStringContainsString('Updated successfully.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deleteRoutes(): iterable
    {
        yield 'workspace' => ['workspace'];
        yield 'service' => ['service'];
        yield 'environment' => ['environment'];
        yield 'endpoint' => ['endpoint'];
    }

    #[DataProvider('deleteRoutes')]
    public function testDeleteWithoutValidCsrfTokenIsDenied(string $kind): void
    {
        $url = match ($kind) {
            'workspace'   => $this->workspaceUrl('/delete'),
            'service'     => $this->serviceUrl('/delete'),
            'environment' => $this->environmentUrl('/delete'),
            default       => $this->endpointUrl('/delete'),
        };

        $this->expectException(AccessDeniedException::class);
        $this->client->request('POST', $url, ['_token' => 'forged']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFoundPages(): iterable
    {
        yield 'unknown workspace' => ['/api-studio/workspaces/999999'];
        yield 'unknown workspace (services)' => ['/api-studio/workspaces/999999/services'];
        yield 'unknown workspace (environments)' => ['/api-studio/workspaces/999999/environments'];
        yield 'unknown workspace (endpoints)' => ['/api-studio/workspaces/999999/services/1/endpoints'];
        yield 'service of another workspace' => ['{other}/services/{service}'];
        yield 'environment of another workspace' => ['{other}/environments/{environment}'];
        yield 'endpoints of a service of another workspace' => ['{other}/services/{service}/endpoints'];
        yield 'unknown endpoint' => ['{workspace}/services/{service}/endpoints/999999'];
    }

    #[DataProvider('notFoundPages')]
    public function testUnknownOrForeignIdsAreNotFound(string $path): void
    {
        $url = str_starts_with($path, '/') ? $path : '/api-studio/workspaces/' . strtr($path, [
            '{other}'       => (string) $this->ids['other'],
            '{workspace}'   => (string) $this->ids['workspace'],
            '{service}'     => (string) $this->ids['service'],
            '{environment}' => (string) $this->ids['environment'],
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', $url);
    }

    public function testEndpointOfAnotherServiceIsNotFound(): void
    {
        $other = null;
        foreach ($this->entityManager()->getRepository(ApiEndpoint::class)->findAll() as $endpoint) {
            if ($endpoint->getService()?->getId() !== $this->ids['service']) {
                $other = $endpoint;
                break;
            }
        }
        self::assertInstanceOf(ApiEndpoint::class, $other);

        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', $this->endpointUrl('', (int) $other->getId()));
    }

    public function testWorkspaceListIsSuccessfulAfterDelete(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit', $this->ids['other']));
        $this->client->submit($crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form());

        self::assertResponseRedirects('/api-studio/workspaces');
        self::assertNull($this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['other']));
        self::assertSame(sprintf('/api-studio/workspaces/%d', $this->ids['other']), $this->workspaceUrl('', $this->ids['other']));
    }
}
