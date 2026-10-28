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
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_column;
use function array_map;
use function count;
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

    public function testWorkspaceListAndDashboardArePaginated(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ApiWorkspace('Zulu workspace', 'zulu'));
        $entityManager->flush();
        $total = $entityManager->getRepository(ApiWorkspace::class)->count([]);
        self::assertGreaterThan(1, $total);

        $crawler = $this->client->request('GET', '/api-studio/workspaces');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.as-data-table tbody tr'));
        self::assertGreaterThan(0, $crawler->filter('a[href*="page=2"]')->count());

        $crawler = $this->client->request('GET', sprintf('/api-studio/workspaces?page=%d', $total));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zulu workspace', $crawler->filter('table.as-data-table tbody')->text());

        // Out-of-range pages are clamped to the last page.
        $this->client->request('GET', '/api-studio/workspaces?page=999');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/api-studio/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.as-project-grid .as-project-card'));
        self::assertGreaterThan(0, $crawler->filter('a[href$="/api-studio/workspaces"]')->count());
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

    public function testEndpointShowRendersTesterAndLayoutControlsFromForms(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl(''));
        self::assertResponseIsSuccessful();

        // REQ-TWIG-005: same ids / names / classes the scripts read, now rendered from Symfony Form views.
        foreach ([
            'select#tester-environment.as-select',
            'select#tester-method.as-url-method-select',
            'input#tester-url-display.as-url-input[readonly]',
            'input#tester-persist-vars[type="checkbox"]',
            'textarea#tester-body.as-code-editor',
            'textarea#tester-pre-script',
            'textarea#tester-post-script',
            'input#tester-example-name.as-input',
            'input#endpoint-doc-title-input.as-input',
            'textarea#endpoint-doc-desc-input',
            'input#as-tree-filter.as-sidebar-search',
            'form#as-service-import-form input#as-service-import-file[name="import_file_form[file]"]',
            'form#as-service-import-form input[name="import_file_form[_token]"]',
            'form#as-service-import-form input[name="import_file_form[importVariables]"]',
            'form#as-service-delete-form input#as-service-delete-csrf[name="_token"]',
            'form#as-service-delete-form input#as-service-delete-redirect[name="redirect"]',
            'form#as-service-delete-form input#as-service-delete-input[name="confirmation"]',
            'label#as-service-delete-label',
        ] as $selector) {
            self::assertCount(1, $crawler->filter($selector), $selector);
        }
        self::assertCount(1, $crawler->filter('input[type="radio"][name="as-export-format"][value="openapi"][checked]'));
        self::assertCount(2, $crawler->filter('input[type="radio"][name="as-import-format"]'));
        self::assertGreaterThan(0, $crawler->filter('#tester-headers-body tr.as-kv-row[data-header-row] input.as-kv-key')->count());
        self::assertCount(
            $crawler->filter('#tester-params-body tr.as-kv-row')->count(),
            $crawler->filter('#tester-params-body input.as-kv-enabled[checked]'),
        );
    }

    public function testSidebarTreeIsOneQueryAndMatchesTheEntityCollections(): void
    {
        $container = self::getContainer();
        /** @var StudioNavigationProvider $provider */
        $provider = $container->get(StudioNavigationProvider::class);
        /** @var DebugDataHolder $queries */
        $queries = $container->get('doctrine.debug_data_holder');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();

        $queries->reset();
        $tree = $provider->buildTree();
        self::assertCount(1, $queries->getData()['default'] ?? [], 'sidebar tree must not walk lazy collections');

        // Same order and content as walking the #[OrderBy] collections.
        $expected = [];
        foreach ($entityManager->getRepository(ApiWorkspace::class)->findBy([], ['name' => 'ASC']) as $workspace) {
            $services = [];
            foreach ($workspace->getServices() as $service) {
                $endpoints = [];
                foreach ($service->getEndpoints() as $endpoint) {
                    $examples = [];
                    foreach ($endpoint->getRequestExamples() as $example) {
                        $examples[] = $example->getId();
                    }
                    $endpoints[] = [$endpoint->getId(), $endpoint->getMethod()->value, $examples];
                }
                $services[] = [$service->getId(), $service->getProtocol()->value, $endpoints];
            }
            $expected[] = [$workspace->getId(), $services];
        }
        $actual = array_map(static fn (array $w): array => [$w['id'], array_map(
            static fn (array $s): array => [$s['id'], $s['protocol'], array_map(
                static fn (array $e): array => [$e['id'], $e['method'], array_column($e['request_examples'], 'id')],
                $s['endpoints'],
            )],
            $w['services'],
        )], $tree);

        self::assertNotSame([], $expected);
        self::assertGreaterThan(1, count($expected[0][1]), 'seed has several services');
        self::assertSame($expected, $actual);
    }

    public function testWorkspaceDeletePostsThroughCsrfForm(): void
    {
        $crawler = $this->client->request('GET', sprintf('/api-studio/workspaces/%d/edit', $this->ids['workspace']));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form.as-delete-form')->form();
        self::assertSame('POST', $form->getMethod());
        $this->client->submit($form);

        self::assertResponseRedirects();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($entityManager->getRepository(ApiWorkspace::class)->find($this->ids['workspace']));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function serviceDeleteRedirects(): iterable
    {
        yield 'local path' => ['/api-studio/somewhere', true];
        yield 'protocol-relative' => ['//evil.test/x', false];
        yield 'backslash protocol-relative' => ['/\\evil.test/x', false];
        yield 'absolute url' => ['https://evil.test/x', false];
        yield 'control character' => ["/\tevil", false];
    }

    #[DataProvider('serviceDeleteRedirects')]
    public function testServiceDeleteRedirectsOnlyToLocalPaths(string $redirect, bool $kept): void
    {
        $crawler = $this->client->request('GET', sprintf('/api-studio/workspaces/%d', $this->ids['workspace']));
        // The sidebar script sets the form action per service: post to the delete route directly with the form token.
        $token = (string) $crawler->filter(sprintf('[data-delete-token][data-delete-url$="/services/%d/delete"]', $this->ids['service']))->first()->attr('data-delete-token');
        // Wrong confirmation: nothing is deleted, the controller only redirects.
        $this->client->request('POST', sprintf('/api-studio/workspaces/%d/services/%d/delete', $this->ids['workspace'], $this->ids['service']), [
            '_token'       => $token,
            'redirect'     => $redirect,
            'confirmation' => 'not the service name',
        ]);

        self::assertResponseRedirects($kept ? $redirect : sprintf('/api-studio/workspaces/%d', $this->ids['workspace']));
    }

    public function testEndpointDeleteFromConfirmDialog(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl('/edit'));
        $form    = $crawler->filter('dialog#endpoint-delete-confirm form.as-delete-form')->form();
        $this->client->submit($form);

        self::assertResponseRedirects();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($entityManager->getRepository(ApiEndpoint::class)->find($this->ids['endpoint']));
    }

    private function endpointUrl(string $suffix): string
    {
        return sprintf('/api-studio/workspaces/%d/services/%d/endpoints/%d%s', $this->ids['workspace'], $this->ids['service'], $this->ids['endpoint'], $suffix);
    }
}
