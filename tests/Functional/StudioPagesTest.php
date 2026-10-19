<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\DemoSeedService;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;
use function str_repeat;

/**
 * Renders the Api Studio GET pages against a seeded SQLite database (strict Twig variables).
 */
final class StudioPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var array{workspace: int, service: int, endpoint: int, environment: int} */
    private array $ids;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->catchExceptions(false);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var DemoSeedService $seeder */
        $seeder = self::getContainer()->get(DemoSeedService::class);
        $seeder->seed();
        $entityManager->clear();

        $workspace = $entityManager->getRepository(ApiWorkspace::class)->findOneBy(['slug' => 'demo']);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        $service = $entityManager->getRepository(ApiService::class)->findOneBy(['workspace' => $workspace]);
        self::assertInstanceOf(ApiService::class, $service);
        $endpoint = $entityManager->getRepository(ApiEndpoint::class)->findOneBy(['service' => $service]);
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);
        $environment = $entityManager->getRepository(ApiEnvironment::class)->findOneBy(['workspace' => $workspace]);
        self::assertInstanceOf(ApiEnvironment::class, $environment);

        $this->ids = [
            'workspace'   => (int) $workspace->getId(),
            'service'     => (int) $service->getId(),
            'endpoint'    => (int) $endpoint->getId(),
            'environment' => (int) $environment->getId(),
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function pageProvider(): iterable
    {
        yield 'dashboard' => ['/api-studio/'];
        yield 'workspace index' => ['/api-studio/workspaces'];
        yield 'workspace new' => ['/api-studio/workspaces/new'];
        yield 'workspace show' => ['/api-studio/workspaces/{workspace}'];
        yield 'workspace edit' => ['/api-studio/workspaces/{workspace}/edit'];
        yield 'service index' => ['/api-studio/workspaces/{workspace}/services'];
        yield 'service new' => ['/api-studio/workspaces/{workspace}/services/new'];
        yield 'service show' => ['/api-studio/workspaces/{workspace}/services/{service}'];
        yield 'service edit' => ['/api-studio/workspaces/{workspace}/services/{service}/edit'];
        yield 'environment index' => ['/api-studio/workspaces/{workspace}/environments'];
        yield 'environment new' => ['/api-studio/workspaces/{workspace}/environments/new'];
        yield 'environment show' => ['/api-studio/workspaces/{workspace}/environments/{environment}'];
        yield 'environment edit' => ['/api-studio/workspaces/{workspace}/environments/{environment}/edit'];
        yield 'endpoint index' => ['/api-studio/workspaces/{workspace}/services/{service}/endpoints'];
        yield 'endpoint new' => ['/api-studio/workspaces/{workspace}/services/{service}/endpoints/new'];
        yield 'endpoint show' => ['/api-studio/workspaces/{workspace}/services/{service}/endpoints/{endpoint}'];
        yield 'endpoint edit' => ['/api-studio/workspaces/{workspace}/services/{service}/endpoints/{endpoint}/edit'];
        yield 'import/export hub' => ['/api-studio/workspaces/{workspace}/import-export'];
    }

    #[DataProvider('pageProvider')]
    public function testPageRenders(string $path): void
    {
        $url = strtr($path, [
            '{workspace}'   => (string) $this->ids['workspace'],
            '{service}'     => (string) $this->ids['service'],
            '{endpoint}'    => (string) $this->ids['endpoint'],
            '{environment}' => (string) $this->ids['environment'],
        ]);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public function testEndpointFormRendersLocaleTabsWithTheDefaultLocaleFirst(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl('/edit'));
        self::assertResponseIsSuccessful();

        $root = $crawler->filter('[data-testid="endpoint-form-locale-tabs"] #endpoint-form-locale[data-nowo-ui-locale-tabs]');
        self::assertCount(1, $root);
        self::assertSame(['en', 'es'], $root->filter('[role="tab"]')->each(static fn ($t): string => (string) $t->attr('data-locale')), 'default locale (en) first');
        self::assertNotNull($crawler->filter('#tab-endpoint-form-locale-en')->attr('data-default'));
        self::assertSame('en', $crawler->filter('#tab-endpoint-form-locale-en-panel input[type="hidden"][name$="[locale]"]')->attr('value'));
        self::assertSame('es', $crawler->filter('#tab-endpoint-form-locale-es-panel input[type="hidden"][name$="[locale]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('#tab-endpoint-form-locale-es-panel input[name$="[title]"]'));
        self::assertCount(0, $crawler->filter('input[type="text"][name$="[locale]"]'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('api-form-locale-tabs', $html);
        self::assertStringNotContainsString('onsubmit', $html);
        self::assertStringContainsString('js/nowo-ui-tabs.js', $html);
        self::assertCount(1, $crawler->filter('dialog#endpoint-delete-confirm[data-nowo-ui-confirm] form[action$="/delete"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('[data-nowo-confirm-open][data-nowo-confirm-target="endpoint-delete-confirm"]'));
    }

    public function testEndpointFormMarksAndOpensTheInvalidLocaleTab(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl('/edit'));
        $form    = $crawler->filter('form.as-form')->form();
        $title   = $crawler->filter('#tab-endpoint-form-locale-es-panel input[name$="[title]"]')->attr('name');
        self::assertIsString($title);
        $form[$title] = str_repeat('x', 300);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('endpoint-form-locale-es', $crawler->filter('#endpoint-form-locale')->attr('data-nowo-ui-tabs-active'), 'the invalid tab opens');
        self::assertNotNull($crawler->filter('#tab-endpoint-form-locale-es')->attr('data-invalid'));
        self::assertNull($crawler->filter('#tab-endpoint-form-locale-en')->attr('data-invalid'));
        self::assertSame('true', $crawler->filter('#tab-endpoint-form-locale-es-panel input[name$="[title]"]')->attr('aria-invalid'));
    }

    public function testEndpointShowRendersReadOnlyDocumentationTabs(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl(''));
        self::assertResponseIsSuccessful();

        $root = $crawler->filter('#endpoint-doc-view [data-testid="endpoint-doc-locale-tabs"]');
        self::assertCount(1, $root);
        self::assertSame('endpoint-doc-locale', $root->attr('id'));
        self::assertSame(['en', 'es'], $root->filter('[role="tab"]')->each(static fn ($t): string => (string) $t->attr('data-locale')));
        self::assertCount(1, $crawler->filter('#tab-endpoint-doc-locale-es-panel [data-doc-title]'));
        self::assertCount(1, $crawler->filter('#tab-endpoint-doc-locale-en-panel [data-doc-description]'));
        self::assertCount(0, $crawler->filter('#endpoint-doc-locale-tabs'));
    }

    private function endpointUrl(string $suffix): string
    {
        return sprintf('/api-studio/workspaces/%d/services/%d/endpoints/%d%s', $this->ids['workspace'], $this->ids['service'], $this->ids['endpoint'], $suffix);
    }
}
