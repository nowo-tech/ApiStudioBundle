<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

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
}
