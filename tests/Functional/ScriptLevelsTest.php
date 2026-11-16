<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiScriptVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\PostmanCollectionImporter;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestFolderChecker;

use function array_column;
use function array_fill_keys;
use function array_map;
use function is_array;
use function json_decode;
use function json_encode;
use function range;
use function sprintf;
use function str_repeat;

/**
 * Spec 003 (1.15), environment "folders_sandbox": the sandbox frame and pm.sendRequest endpoints, and workspace /
 * folder scripts edited through their forms and handed to the console in chain order.
 */
final class ScriptLevelsTest extends StudioWebTestCase
{
    protected static function clientOptions(): array
    {
        return ['environment' => 'folders_sandbox'];
    }

    public function testFrameDocumentIsServedWithItsOwnCsp(): void
    {
        $this->client->request('GET', '/api-studio/scripts/sandbox');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith("default-src 'none'; script-src 'nonce-", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testWorkspaceAndFolderScriptsReachTheConsole(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit'));
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[preRequestScript]'  => "pm.console.log('workspace pre')",
            'api_workspace_form[postRequestScript]' => "pm.console.log('workspace post')",
        ]));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', $this->serviceUrl('/folders/new'));
        $this->client->submit($crawler->filter('form[name="api_folder_form"]')->form([
            'api_folder_form[name]'             => 'Users',
            'api_folder_form[preRequestScript]' => "pm.console.log('folder pre')",
        ]));
        self::assertResponseRedirects($this->serviceUrl());
        $folder = $this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['name' => 'Users']);
        self::assertInstanceOf(ApiFolder::class, $folder);

        $crawler = $this->client->request('GET', $this->serviceUrl());
        $token   = (string) $crawler->filter(sprintf('[data-tree-service="%d"]', $this->ids['service']))->attr('data-tree-csrf');
        $this->client->request('POST', $this->serviceUrl('/folders/move'), server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => $folder->getId(), 'before' => null]));
        self::assertResponseIsSuccessful();

        $tester = $this->client->request('GET', $this->endpointUrl())->filter('#api-studio-tester');
        self::assertSame('sandbox', $tester->attr('data-scripts-mode'));
        self::assertSame('/api-studio/scripts/sandbox', $tester->attr('data-scripts-sandbox-url'));
        self::assertSame('/api-studio/scripts/send-request/' . $this->ids['endpoint'], $tester->attr('data-scripts-send-url'));
        $levels = json_decode((string) $tester->attr('data-level-scripts'), true);
        self::assertSame([
            'workspace' => ['pre' => "pm.console.log('workspace pre')", 'post' => "pm.console.log('workspace post')"],
            'folders'   => [['name' => 'Users', 'pre' => "pm.console.log('folder pre')", 'post' => '']],
        ], $levels);

        $this->entityManager()->clear();
        $workspace = $this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['workspace']);
        self::assertSame("pm.console.log('workspace pre')", $workspace?->getPreRequestScript());
    }

    public function testSendRequestRefusesAPrivateAddress(): void
    {
        $token = (string) $this->client->request('GET', $this->endpointUrl())->filter('#api-studio-tester')->attr('data-csrf-token');
        $this->client->request('POST', '/api-studio/scripts/send-request/' . $this->ids['endpoint'], server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: '{"url":"http://127.0.0.1/admin"}');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('not allowed', is_array($data) ? (string) ($data['error'] ?? '') : '');
    }

    public function testGlobalsAndCollectionVariablesArePersistedAndHandedToTheConsole(): void
    {
        $token = $this->syncToken();
        $data  = $this->saveVariables($token, ['globals' => ['host' => 'example.org', 'gone' => 'x'], 'collection' => ['{{page}}' => '2']]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $data['written']);

        $data = $this->saveVariables($token, ['globals' => ['gone' => null, 'host' => 'example.com']]);
        self::assertSame(['host' => 'example.com'], $data['globals']);
        self::assertSame(['page' => '2'], $data['collection']);

        $tester = $this->client->request('GET', $this->endpointUrl())->filter('#api-studio-tester');
        self::assertSame('/api-studio/scripts/variables/' . $this->ids['endpoint'], $tester->attr('data-script-variables-url'));
        self::assertSame(['globals' => ['host' => 'example.com'], 'collection' => ['page' => '2']], json_decode((string) $tester->attr('data-script-variables'), true));

        // Another service of the workspace sees the globals but not these collection variables.
        $this->saveVariables($token, ['collection' => ['page' => '3']]);
        $this->entityManager()->clear();
        $rows = $this->entityManager()->getConnection()->fetchAllAssociative('SELECT variable_key, value, scope_id, service_id FROM api_studio_script_variable ORDER BY id');
        self::assertSame([
            ['variable_key' => 'host', 'value' => 'example.com', 'scope_id' => 0, 'service_id' => null],
            ['variable_key' => 'page', 'value' => '3', 'scope_id' => $this->ids['service'], 'service_id' => $this->ids['service']],
        ], array_map(static fn (array $row): array => ['variable_key' => $row['variable_key'], 'value' => $row['value'], 'scope_id' => (int) $row['scope_id'], 'service_id' => $row['service_id'] === null ? null : (int) $row['service_id']], $rows));
    }

    public function testVariableSaveRefusals(): void
    {
        $token = $this->syncToken();

        $this->saveVariables('wrong', ['globals' => ['a' => '1']]);
        self::assertResponseStatusCodeSame(403);
        $this->saveVariables($token, ['globals' => ['a' => '1']], 999999);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/api-studio/scripts/variables/' . $this->ids['endpoint'], server: ['HTTP_X_CSRF_TOKEN' => $token], content: 'nope');
        self::assertResponseStatusCodeSame(400);
        $this->saveVariables($token, ['globals' => 'x']);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('{{variable_name}}', (string) ($this->saveVariables($token, ['globals' => ['bad key' => '1']])['error'] ?? ''));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Invalid variable change.', $this->saveVariables($token, ['collection' => ['a' => 1]])['error'] ?? null);
        self::assertStringContainsString('too long', (string) ($this->saveVariables($token, ['globals' => ['a' => str_repeat('x', ApiScriptVariable::MAX_VALUE_BYTES + 1)]])['error'] ?? ''));
        // Audit RW-L3: the TEXT column holds bytes, not characters; keys fit the 128-character column.
        self::assertStringContainsString('at most 65535 bytes', (string) ($this->saveVariables($token, ['globals' => ['a' => str_repeat('é', 33_000)]])['error'] ?? ''));
        self::assertStringContainsString('at most 128 characters', (string) ($this->saveVariables($token, ['collection' => [str_repeat('k', 129) => '1']])['error'] ?? ''));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('At most 100', (string) ($this->saveVariables($token, ['globals' => array_fill_keys(array_map(static fn (int $i): string => 'k' . $i, range(1, 101)), '1')])['error'] ?? ''));

        // Nothing was written by a refused save, even for the valid scope of the payload.
        $this->saveVariables($token, ['globals' => ['ok' => '1'], 'collection' => ['bad key' => '1']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_script_variable'));
    }

    public function testAScopeIsCapped(): void
    {
        $token = $this->syncToken();
        for ($batch = 0; $batch < 5; ++$batch) {
            $keys = array_map(static fn (int $i): string => sprintf('k%d_%d', $batch, $i), range(1, 100));
            $this->saveVariables($token, ['globals' => array_fill_keys($keys, 'v')]);
            self::assertResponseIsSuccessful();
        }

        self::assertStringContainsString('At most 500 variables', (string) ($this->saveVariables($token, ['globals' => ['one_more' => 'v']])['error'] ?? ''));
        // Updating and removing existing keys of a full scope still works.
        self::assertTrue($this->saveVariables($token, ['globals' => ['k0_1' => 'w', 'k0_2' => null]])['success'] ?? false);
    }

    public function testFolderRulesApplyToTheSave(): void
    {
        $crawler = $this->client->request('GET', $this->serviceUrl('/folders/new'));
        $this->client->submit($crawler->filter('form[name="api_folder_form"]')->form(['api_folder_form[name]' => 'Locked']));
        $folder = $this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['name' => 'Locked']);
        self::assertInstanceOf(ApiFolder::class, $folder);
        $crawler = $this->client->request('GET', $this->serviceUrl());
        $move    = (string) $crawler->filter(sprintf('[data-tree-service="%d"]', $this->ids['service']))->attr('data-tree-csrf');
        $this->client->request('POST', $this->serviceUrl('/folders/move'), server: ['HTTP_X_CSRF_TOKEN' => $move, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['type' => 'endpoint', 'id' => $this->ids['endpoint'], 'parent' => $folder->getId(), 'before' => null]));
        self::assertResponseIsSuccessful();
        $token = $this->syncToken();
        // The checker instance must survive between requests.
        $this->client->disableReboot();

        /** @var TestFolderChecker $checker */
        $checker           = $this->client->getContainer()->get(TestFolderChecker::class);
        $checker->readOnly = [(int) $folder->getId()];
        $this->saveVariables($token, ['globals' => ['a' => '1']]);
        self::assertResponseStatusCodeSame(403);

        $checker->hidden = [(int) $folder->getId()];
        $this->saveVariables($token, ['globals' => ['a' => '1']]);
        self::assertResponseStatusCodeSame(404);
    }

    private function syncToken(): string
    {
        return (string) $this->client->request('GET', $this->endpointUrl())->filter('#api-studio-tester')->attr('data-env-sync-csrf');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function saveVariables(string $token, array $payload, ?int $endpoint = null): array
    {
        $this->client->request('POST', '/api-studio/scripts/variables/' . ($endpoint ?? $this->ids['endpoint']), server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    public function testPostmanEventScriptsLandOnTheirLevel(): void
    {
        $event      = static fn (string $listen, string|array $exec, bool $disabled = false): array => ['listen' => $listen, 'script' => ['exec' => $exec], 'disabled' => $disabled];
        $collection = [
            'info'  => ['name' => 'Scripted', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'event' => [$event('prerequest', ["pm.environment.set('a', 1);", 'pm.console.log(1);']), $event('test', 'pm.test("ok", () => {});')],
            'item'  => [
                ['name' => 'Users', 'event' => [$event('test', 'folder post'), $event('prerequest', 'ignored', true)], 'item' => [
                    ['name' => 'List', 'event' => [$event('prerequest', 'request pre'), $event('prerequest', [' ']), $event('sync', 'odd')], 'request' => ['method' => 'GET', 'url' => 'https://example.org/users']],
                ]],
            ],
        ];
        $workspace = $this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['workspace']);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        /** @var PostmanCollectionImporter $importer */
        $importer = self::getContainer()->get(PostmanCollectionImporter::class);

        $refused = $importer->import($workspace, (string) json_encode($collection), 'a.json', false);
        self::assertContains('5 scripts were not imported (they need scripts.mode: sandbox, the right to edit scripts and, for folder scripts, folders created by this import).', $refused->messages);
        $this->entityManager()->clear();

        $workspace = $this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['workspace']);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        $collection['info']['name'] = 'Scripted 2';
        $result                     = $importer->import($workspace, (string) json_encode($collection), 'b.json', false, null, true);
        $this->entityManager()->flush();
        self::assertContains('4 scripts were imported (they run in the sandboxed runner).', $result->messages);
        self::assertContains('1 scripts were not imported (they need scripts.mode: sandbox, the right to edit scripts and, for folder scripts, folders created by this import).', $result->messages, 'the "sync" event');

        $this->entityManager()->clear();
        $service = $this->entityManager()->getRepository(ApiService::class)->findOneBy(['name' => 'Scripted 2']);
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame("pm.environment.set('a', 1);\npm.console.log(1);", $service->getPreRequestScript());
        self::assertSame('pm.test("ok", () => {});', $service->getPostRequestScript());
        $folder = $this->entityManager()->getRepository(ApiFolder::class)->findOneBy(['service' => $service, 'name' => 'Users']);
        self::assertInstanceOf(ApiFolder::class, $folder);
        self::assertNull($folder->getPreRequestScript(), 'disabled event');
        self::assertSame('folder post', $folder->getPostRequestScript());
        $endpoint = $this->entityManager()->getRepository(ApiEndpoint::class)->findOneBy(['service' => $service]);
        self::assertSame('request pre', $endpoint?->getPreRequestScript());

        // Into an existing service: the collection scripts never replace the service's own.
        $existing = $this->entityManager()->getRepository(ApiService::class)->find($this->ids['service']);
        self::assertInstanceOf(ApiService::class, $existing);
        $before = $existing->getPreRequestScript();
        $into   = $importer->import($existing->getWorkspace() ?? $workspace, (string) json_encode($collection), 'c.json', false, $existing, true);
        self::assertContains('2 scripts were imported (they run in the sandboxed runner).', $into->messages);
        self::assertContains('3 scripts were not imported (they need scripts.mode: sandbox, the right to edit scripts and, for folder scripts, folders created by this import).', $into->messages);
        self::assertSame($before, $existing->getPreRequestScript());
    }

    /**
     * US5 / SC-003: the console page of sandbox mode hands scripts secret variables as their own placeholder only; no
     * attribute script code can read (environment maps, levels, globals, collection variables) carries the value.
     */
    public function testNoSecretValueReachesTheScriptContext(): void
    {
        $html   = (string) $this->client->request('GET', $this->endpointUrl())->html();
        $tester = $this->client->getCrawler()->filter('#api-studio-tester');

        self::assertStringNotContainsString('YOUR_GOOGLE_CLOUD_API_KEY', $html);
        self::assertStringNotContainsString('YOUR_LINKEDIN_OAUTH2_ACCESS_TOKEN', $html);
        $maps = json_decode((string) $tester->attr('data-environment-maps'), true);
        self::assertIsArray($maps);
        $variables = array_column($maps, 'variables');
        self::assertNotEmpty($variables);
        foreach ($variables as $map) {
            self::assertSame('{{google_api_key}}', $map['google_api_key'] ?? null);
            self::assertSame('{{linkedin_access_token}}', $map['linkedin_access_token'] ?? null);
        }
    }
}
