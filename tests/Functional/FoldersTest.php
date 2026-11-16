<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiImporter;
use Nowo\ApiStudioBundle\Service\ImportExport\PostmanCollectionImporter;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestFolderChecker;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function is_array;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function sprintf;

/**
 * 1.14 folders (environment "folders": folders on, max depth 3, TestFolderChecker): CRUD, depth, moves, the tree,
 * inherited auth in the console, folder-aware import / export and the folder sharing hook.
 */
final class FoldersTest extends StudioWebTestCase
{
    protected static function clientOptions(): array
    {
        return ['environment' => 'folders'];
    }

    public function testCreateNestedFoldersUpToTheMaxDepth(): void
    {
        $top     = $this->createFolder('Users');
        $admin   = $this->createFolder('Admin', $top);
        $deep    = $this->createFolder('Deep', $admin);
        $crawler = $this->client->request('GET', $this->folderUrl('/new?parent=' . $deep));
        $this->client->submit($crawler->filter('form[name="api_folder_form"]')->form(['api_folder_form[name]' => 'Too deep']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('3 levels', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['name' => 'Too deep']));

        $crawler = $this->client->request('GET', $this->serviceUrl());
        self::assertResponseIsSuccessful();
        self::assertSame(['Users', 'Admin', 'Deep'], $crawler->filter('[data-testid="api-studio-folder-row"] a:first-of-type')->each(static fn ($a): string => $a->text()));
        self::assertGreaterThan(0, $crawler->filter('#as-nav-tree [data-tree-folder][data-tree-id="' . $deep . '"]')->count());
    }

    public function testEditRefusesACycleAndDeleteMovesContentUp(): void
    {
        $top   = $this->createFolder('Users');
        $child = $this->createFolder('Admin', $top);
        $this->moveEndpointTo($child);

        $crawler     = $this->client->request('GET', $this->folderUrl(sprintf('/%d/edit', $top)));
        $parentField = $crawler->filter('form[name="api_folder_form"]')->form()['api_folder_form[parent]'];
        self::assertInstanceOf(ChoiceFormField::class, $parentField);
        self::assertNotContains((string) $child, $parentField->availableOptionValues(), 'descendants are not offered');

        $this->client->submit($crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form());
        self::assertResponseRedirects($this->serviceUrl());
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="api-studio-service-folders"]'));

        $em = $this->entityManager();
        $em->clear();
        $admin = $em->getRepository(ApiFolder::class)->find($child);
        self::assertInstanceOf(ApiFolder::class, $admin);
        self::assertNull($admin->getParent());
        self::assertNull($em->getRepository(ApiFolder::class)->find($top));
        self::assertSame($child, $em->getRepository(ApiEndpoint::class)->find($this->ids['endpoint'])?->getFolder()?->getId());
    }

    public function testTreeMovesRefuseCyclesOtherServicesAndBadTokens(): void
    {
        $top   = $this->createFolder('Users');
        $child = $this->createFolder('Admin', $top);
        $token = $this->moveToken();

        $this->move(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => $child, 'before' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertSame($child, $this->entityManager()->getRepository(ApiEndpoint::class)->find($this->ids['endpoint'])?->getFolder()?->getId());

        $data = $this->move(['type' => 'folder', 'id' => $top, 'parent' => $child, 'before' => null], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('folder.error.cycle', $data['code']);

        $this->move(['type' => 'folder', 'id' => $child, 'parent' => null, 'before' => $top], $token);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        $folders = $this->entityManager()->getRepository(ApiFolder::class);
        self::assertNull($folders->find($child)?->getParent());
        self::assertLessThan($folders->find($top)?->getSortOrder(), $folders->find($child)?->getSortOrder());

        $this->move(['type' => 'endpoint', 'id' => 'x'], $token);
        self::assertResponseStatusCodeSame(400);

        $this->move(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => null], 'bad-token');
        self::assertResponseStatusCodeSame(403);

        $other = $this->otherServiceFolder();
        $this->client->catchExceptions(true);
        $this->move(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => $other], $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testConsoleShowsTheAuthInheritedFromTheFolder(): void
    {
        $top    = $this->createFolder('Users');
        $em     = $this->entityManager();
        $folder = $em->getRepository(ApiFolder::class)->find($top);
        self::assertInstanceOf(ApiFolder::class, $folder);
        $folder->setAuthType(AuthType::Basic)->setAuthConfig(['username' => 'u', 'password' => 'p'])->setHeaders(['X-Folder' => 'yes']);
        $em->flush();
        $this->moveEndpointTo($top);

        $crawler = $this->client->request('GET', $this->endpointUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Users', $crawler->filter('[data-testid="api-studio-auth-source"]')->text());
        self::assertStringContainsString('X-Folder', (string) $crawler->filter('#api-studio-tester')->attr('data-merged-headers'));
    }

    public function testHiddenFolderIsNotFoundAndReadOnlyFolderIsForbidden(): void
    {
        $top = $this->createFolder('Partners');
        $this->moveEndpointTo($top);
        $executeToken = (string) $this->client->request('GET', $this->endpointUrl())->filter('#api-studio-tester')->attr('data-csrf-token');
        // The checker instance must survive between requests.
        $this->client->disableReboot();
        $this->client->request('GET', $this->serviceUrl());
        /** @var TestFolderChecker $checker */
        $checker = $this->client->getContainer()->get(TestFolderChecker::class);

        $checker->readOnly = [$top];
        $this->client->request('GET', $this->endpointUrl());
        self::assertResponseIsSuccessful();
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->endpointUrl('/edit'));
        self::assertResponseStatusCodeSame(403);

        $checker->readOnly = [];
        $checker->hidden   = [$top];
        $crawler           = $this->client->request('GET', $this->serviceUrl());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-tree-folder][data-tree-id="' . $top . '"]'));
        self::assertCount(0, $crawler->filter('[data-testid="api-studio-folder-row"]'));
        $this->client->request('GET', $this->endpointUrl());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/api-studio/execute/' . $this->ids['endpoint'], server: ['HTTP_X_CSRF_TOKEN' => $executeToken, 'CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(404);

        $service = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        $this->client->request('GET', $this->workspaceUrl(sprintf('/services/%d/export/openapi', $this->ids['service'])));
        self::assertStringNotContainsString('x-api-studio-folder', (string) $this->client->getResponse()->getContent());
    }

    public function testEditSavesAndBadRequestsAreRefused(): void
    {
        $top     = $this->createFolder('Users');
        $crawler = $this->client->request('GET', $this->folderUrl(sprintf('/%d/edit', $top)));
        $this->client->submit($crawler->filter('form[name="api_folder_form"]')->form([
            'api_folder_form[name]'     => 'People',
            'api_folder_form[authType]' => 'none',
            'api_folder_form[headers]'  => '{"X-Team": "people"}',
        ]));
        self::assertResponseRedirects($this->serviceUrl());
        $this->entityManager()->clear();
        $folder = $this->entityManager()->getRepository(ApiFolder::class)->find($top);
        self::assertSame('People', $folder?->getName());
        self::assertSame(AuthType::None, $folder->getAuthType());
        self::assertSame(['X-Team' => 'people'], $folder->getHeaders());

        $this->client->catchExceptions(true);
        $this->client->request('POST', $this->folderUrl(sprintf('/%d/delete', $top)), ['_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->workspaceUrl(sprintf('/services/%d/folders/%d/edit', $this->otherServiceId(), $top)));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->folderUrl('/999999/edit'));
        self::assertResponseStatusCodeSame(404);
        $this->move(['type' => 'endpoint', 'id' => 999999, 'parent' => null], $this->moveToken());
        self::assertResponseStatusCodeSame(404);
    }

    public function testNewEndpointInAFolderAndKeyboardMoveThroughTheForm(): void
    {
        $top     = $this->createFolder('Users');
        $crawler = $this->client->request('GET', $this->serviceUrl('/endpoints/new?folder=' . $top));
        $form    = $crawler->filter('form[name="api_endpoint_form"]')->form([
            'api_endpoint_form[name]' => 'Folder call',
            'api_endpoint_form[slug]' => 'folder-call',
            'api_endpoint_form[path]' => '/in-folder',
        ]);
        $folderField = $form['api_endpoint_form[folder]'];
        self::assertInstanceOf(ChoiceFormField::class, $folderField);
        self::assertSame((string) $top, $folderField->getValue());
        $this->client->submit($form);
        $endpoint = $this->entityManager()->getRepository(ApiEndpoint::class)->findOneBy(['slug' => 'folder-call']);
        self::assertSame($top, $endpoint?->getFolder()?->getId());

        $crawler = $this->client->request('GET', $this->endpointUrl('/edit', (int) $endpoint->getId()));
        $this->client->submit($crawler->filter('form[name="api_endpoint_form"]')->form(['api_endpoint_form[folder]' => '']));
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(ApiEndpoint::class)->find((int) $endpoint->getId())?->getFolder());
    }

    public function testImportIntoAServiceReusesItsFoldersAndReportsUnsupportedAuth(): void
    {
        $service = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        $existing   = $this->createFolder('Users');
        $collection = [
            'info' => ['name' => 'Again', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'auth' => ['type' => 'oauth2', 'oauth2' => []],
            'item' => [
                ['name' => 'Users', 'auth' => ['type' => 'digest'], 'item' => [
                    ['name' => 'Key call', 'request' => ['method' => 'GET', 'url' => '/k', 'auth' => ['type' => 'apikey', 'apikey' => [['key' => 'key', 'value' => 'X-Key'], ['key' => 'value', 'value' => '{{key}}']]]]],
                    ['name' => 'Digest call', 'request' => ['method' => 'GET', 'url' => '/d', 'auth' => ['type' => 'digest']]],
                ]],
                ['name' => 'Users', 'item' => [['name' => 'Second', 'request' => ['method' => 'GET', 'url' => '/s']]]],
                ['name' => 'Orders', 'item' => []],
            ],
        ];
        $service = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        /** @var PostmanCollectionImporter $importer */
        $importer = self::getContainer()->get(PostmanCollectionImporter::class);
        $result   = $importer->import($service->getWorkspace() ?? self::fail('workspace'), (string) json_encode($collection), null, false, $service);
        $this->entityManager()->flush();

        self::assertContains('2 auth settings of an unsupported type (or on a single request) were not imported.', $result->messages);
        $key = $this->entityManager()->getRepository(ApiEndpoint::class)->findOneBy(['name' => 'Key call']);
        self::assertSame($existing, $key?->getFolder()?->getId(), 'the existing "Users" folder is reused');
        self::assertSame('{{key}}', $key->getHeaders()['X-Key'] ?? null);
        self::assertSame($existing, $this->entityManager()->getRepository(ApiEndpoint::class)->findOneBy(['name' => 'Second'])?->getFolder()?->getId());
        self::assertNotNull($this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['name' => 'Orders']));
    }

    public function testKeptAliveKernelNeverReusesFolderDecisionsOfAnotherRequest(): void
    {
        $top = $this->createFolder('Partners');
        $this->moveEndpointTo($top);
        // Worker mode without kernel reset: one kernel, one container, many requests.
        $this->client->disableReboot();
        $this->client->request('GET', $this->serviceUrl());
        /** @var TestFolderChecker $checker */
        $checker = $this->client->getContainer()->get(TestFolderChecker::class);

        $baseline = 0;
        for ($i = 0; $i < 60; ++$i) {
            $checker->hidden = $i % 2 === 0 ? [$top] : [];
            $crawler         = $this->client->request('GET', $this->serviceUrl());
            self::assertCount($i % 2 === 0 ? 0 : 1, $crawler->filter('#as-nav-tree [data-tree-folder][data-tree-id="' . $top . '"]'), 'request ' . $i);
            if ($i === 10) {
                $baseline = memory_get_usage();
            }
        }

        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over the requests');
    }

    public function testPostmanImportKeepsFoldersAuthAndCapsTheDepth(): void
    {
        $collection = [
            'info'  => ['name' => 'Shop', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'auth'  => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}']]],
            'event' => [['listen' => 'prerequest', 'script' => ['exec' => ['console.log(1)']]]],
            'item'  => [
                ['name' => 'Users', 'auth' => ['type' => 'basic', 'basic' => [['key' => 'username', 'value' => 'u'], ['key' => 'password', 'value' => 'p']]], 'item' => [
                    ['name' => 'List users', 'request' => ['method' => 'GET', 'url' => '{{base_url}}/users']],
                    ['name' => 'L2', 'item' => [['name' => 'L3', 'item' => [['name' => 'L4', 'item' => [
                        ['name' => 'Deep call', 'request' => ['method' => 'GET', 'url' => '{{base_url}}/deep']],
                    ]]]]]],
                ]],
                ['name' => 'Ping', 'request' => ['method' => 'GET', 'url' => '{{base_url}}/ping', 'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => 'abc']]]]],
            ],
        ];
        $workspace = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service'])?->getWorkspace();
        self::assertNotNull($workspace);
        /** @var PostmanCollectionImporter $importer */
        $importer = self::getContainer()->get(PostmanCollectionImporter::class);
        $result   = $importer->import($workspace, (string) json_encode($collection), 'shop.postman_collection.json', false);
        $this->entityManager()->flush();

        self::assertSame(3, $result->endpointsCreated);
        self::assertContains('1 folders deeper than the allowed depth were merged into their deepest allowed parent.', $result->messages);
        self::assertContains('1 scripts were not imported (stored scripts from files arrive with the sandboxed runner).', $result->messages);

        $em      = $this->entityManager();
        $service = $em->getRepository(ApiService::class)->findOneBy(['name' => 'Shop']);
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame(AuthType::Bearer, $service->getAuthType());
        $users = $em->getRepository(ApiFolder::class)->findOneBy(['service' => $service, 'name' => 'Users']);
        self::assertInstanceOf(ApiFolder::class, $users);
        self::assertSame(AuthType::Basic, $users->getAuthType());
        $deep = $em->getRepository(ApiEndpoint::class)->findOneBy(['service' => $service, 'name' => 'Deep call']);
        self::assertSame('L3', $deep?->getFolder()?->getName(), 'L4 is merged into L3 (max depth 3)');
        $ping = $em->getRepository(ApiEndpoint::class)->findOneBy(['service' => $service, 'name' => 'Ping']);
        self::assertNull($ping?->getFolder());
        self::assertSame('Bearer abc', $ping?->getHeaders()['Authorization'] ?? null);
    }

    public function testOpenApiTagsAndFolderExtensionRoundTrip(): void
    {
        $document = [
            'openapi' => '3.0.3',
            'info'    => ['title' => 'Pets', 'version' => '1'],
            'tags'    => [['name' => 'Pets', 'description' => 'Pet operations']],
            'paths'   => [
                '/pets'      => ['get' => ['summary' => 'List pets', 'tags' => ['Pets']]],
                '/pets/{id}' => ['delete' => ['summary' => 'Delete pet', 'x-api-studio-folder' => ['Pets', 'Admin']]],
                '/health'    => ['get' => ['summary' => 'Health', 'x-api-studio-folder' => []]],
            ],
        ];
        $workspace = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service'])?->getWorkspace();
        self::assertNotNull($workspace);
        /** @var OpenApiImporter $importer */
        $importer = self::getContainer()->get(OpenApiImporter::class);
        $importer->import($workspace, (string) json_encode($document), 'pets.json');
        $this->entityManager()->flush();

        $em      = $this->entityManager();
        $service = $em->getRepository(ApiService::class)->findOneBy(['name' => 'Pets']);
        self::assertInstanceOf(ApiService::class, $service);
        $pets = $em->getRepository(ApiFolder::class)->findOneBy(['service' => $service, 'name' => 'Pets']);
        self::assertSame('Pet operations', $pets?->getDescription());
        $delete = $em->getRepository(ApiEndpoint::class)->findOneBy(['service' => $service, 'name' => 'Delete pet']);
        self::assertSame(['Pets', 'Admin'], array_map(static fn (ApiFolder $f): string => $f->getName(), $delete?->getFolder()?->getPath() ?? []));

        /** @var OpenApiExporter $exporter */
        $exporter = self::getContainer()->get(OpenApiExporter::class);
        $exported = json_decode($exporter->exportService($service), true);
        self::assertIsArray($exported);
        self::assertSame(['Pets', 'Admin'], $exported['paths']['/pets/{id}']['delete']['x-api-studio-folder']);
        self::assertSame(['Pets'], $exported['paths']['/pets/{id}']['delete']['tags']);
        self::assertSame(['Pets'], $exported['paths']['/health']['get']['tags']);
        self::assertSame([['name' => 'Pets', 'description' => 'Pet operations']], $exported['tags']);
    }

    private function folderUrl(string $suffix): string
    {
        return $this->serviceUrl('/folders' . $suffix);
    }

    private function createFolder(string $name, ?int $parent = null): int
    {
        $crawler = $this->client->request('GET', $this->folderUrl('/new' . ($parent !== null ? '?parent=' . $parent : '')));
        $this->client->submit($crawler->filter('form[name="api_folder_form"]')->form(['api_folder_form[name]' => $name]));
        self::assertResponseRedirects($this->serviceUrl());
        $this->entityManager()->clear();
        $folder = $this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['name' => $name], ['id' => 'DESC']);
        self::assertInstanceOf(ApiFolder::class, $folder);

        return (int) $folder->getId();
    }

    private function moveEndpointTo(int $folder): void
    {
        $this->move(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => $folder, 'before' => null], $this->moveToken());
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
    }

    private function moveToken(): string
    {
        $crawler = $this->client->request('GET', $this->serviceUrl());

        return (string) $crawler->filter(sprintf('[data-tree-service="%d"]', $this->ids['service']))->attr('data-tree-csrf');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function move(array $payload, string $token): array
    {
        $this->client->request('POST', $this->folderUrl('/move'), server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function otherServiceId(): int
    {
        $service = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        foreach ($service?->getWorkspace()?->getServices() ?? [] as $candidate) {
            if ($candidate->getId() !== $this->ids['service']) {
                return (int) $candidate->getId();
            }
        }
        self::fail('the demo seed has more than one service');
    }

    private function otherServiceFolder(): int
    {
        $em      = $this->entityManager();
        $service = $em->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $service);
        $other = null;
        foreach ($service->getWorkspace()?->getServices() ?? [] as $candidate) {
            if ($candidate->getId() !== $service->getId()) {
                $other = $candidate;
            }
        }
        self::assertInstanceOf(ApiService::class, $other, 'the demo seed has more than one service');
        $folder = (new ApiFolder('Elsewhere'))->setService($other);
        $em->persist($folder);
        $em->flush();

        return (int) $folder->getId();
    }
}
