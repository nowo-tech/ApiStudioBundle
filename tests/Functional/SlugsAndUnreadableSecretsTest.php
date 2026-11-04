<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * 1.10.1: a duplicate slug is a form error with a 422 (never a 500) for workspaces, services, environments and
 * endpoints; a secret the configured key cannot decrypt fails closed (never sent, never exported, never re-saved as
 * ciphertext, shown as "needs re-entry").
 */
final class SlugsAndUnreadableSecretsTest extends StudioWebTestCase
{
    private const TAKEN = 'already used here';

    public function testSecondWorkspaceWithTheDefaultSlugIs422(): void
    {
        foreach ([302, 422] as $expected) {
            $crawler = $this->client->request('GET', '/api-studio/workspaces/new');
            $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form());
            self::assertResponseStatusCodeSame($expected);
        }
        self::assertStringContainsString(self::TAKEN, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->entityManager()->getRepository(ApiWorkspace::class)->count(['slug' => 'new_workspace']));
    }

    public function testRenamingAWorkspaceToATakenSlugIs422(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit'));
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form(['api_workspace_form[slug]' => 'other']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::TAKEN, (string) $this->client->getResponse()->getContent());
    }

    public function testDuplicateServiceEnvironmentAndEndpointSlugsAre422(): void
    {
        $em          = $this->entityManager();
        $service     = $em->find(ApiService::class, $this->ids['service']);
        $environment = $em->find(ApiEnvironment::class, $this->ids['environment']);
        $endpoint    = $em->find(ApiEndpoint::class, $this->ids['endpoint']);
        self::assertInstanceOf(ApiService::class, $service);
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);

        $crawler = $this->client->request('GET', $this->workspaceUrl('/services/new'));
        $this->client->submit($crawler->filter('form[name="api_service_form"]')->form([
            'api_service_form[name]'    => 'Copy',
            'api_service_form[slug]'    => $service->getSlug(),
            'api_service_form[baseUrl]' => 'https://copy.example.test',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::TAKEN, (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', $this->workspaceUrl('/environments/new'));
        $this->client->submit($crawler->filter('form[name="api_environment_form"]')->form([
            'api_environment_form[name]' => 'Copy',
            'api_environment_form[slug]' => $environment->getSlug(),
        ]));
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->client->request('GET', $this->serviceUrl('/endpoints/new'));
        $this->client->submit($crawler->filter('form[name="api_endpoint_form"]')->form([
            'api_endpoint_form[name]' => 'Copy',
            'api_endpoint_form[slug]' => $endpoint->getSlug(),
            'api_endpoint_form[path]' => '/copy',
        ]));
        self::assertResponseStatusCodeSame(422);

        // The same slug in another workspace is fine.
        $crawler = $this->client->request('GET', $this->workspaceUrl('/services/new', $this->ids['other']));
        $this->client->submit($crawler->filter('form[name="api_service_form"]')->form([
            'api_service_form[name]'    => 'Copy',
            'api_service_form[slug]'    => $service->getSlug(),
            'api_service_form[baseUrl]' => 'https://copy.example.test',
        ]));
        self::assertResponseRedirects();
    }

    public function testUnreadableSecretIsShownAsNeedsReentryAndNeverSent(): void
    {
        [$variable, $endpoint] = $this->corruptSecret();
        $environmentId         = (int) $variable->getEnvironment()?->getId();

        $crawler = $this->client->request('GET', $this->environmentUrl('', $environmentId));
        self::assertSelectorExists('[data-testid="api-studio-environment-needs-reentry"]');
        self::assertSelectorExists('[data-testid="api-studio-variable-needs-reentry"]');

        $service = $endpoint->getService();
        self::assertInstanceOf(ApiService::class, $service);
        $crawler = $this->client->request('GET', sprintf('/api-studio/workspaces/%d/services/%d/endpoints/%d', $this->ids['workspace'], (int) $service->getId(), (int) $endpoint->getId()));
        $token   = (string) $crawler->filter('#api-studio-tester')->attr('data-csrf-token');
        $history = $this->entityManager()->getRepository(ApiRequestHistory::class)->count([]);

        $this->client->request('POST', sprintf('/api-studio/execute/%d', (int) $endpoint->getId()), [], [], ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['environment_id' => $environmentId]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('secret_unreadable', $data['error_code']);
        self::assertSame(['linkedin_access_token'], $data['unreadable_variables']);
        self::assertStringNotContainsString('nowo_as_enc_', (string) $this->client->getResponse()->getContent());
        self::assertSame($history + 1, $this->entityManager()->getRepository(ApiRequestHistory::class)->count([]), 'The failure is in the history.');
    }

    public function testUnreadableSecretIsExportedWithoutValueAndKeptOnSave(): void
    {
        [$variable]    = $this->corruptSecret();
        $environmentId = (int) $variable->getEnvironment()?->getId();

        $this->client->request('GET', $this->workspaceUrl(sprintf('/environments/%d/export/variables.json', $environmentId)));
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('nowo_as_enc_', $content);
        $export = json_decode($content, true);
        self::assertIsArray($export);
        $row = null;
        foreach ($export['variables'] as $candidate) {
            if (is_array($candidate) && $candidate['key'] === '{{linkedin_access_token}}') {
                $row = $candidate;
            }
        }
        self::assertSame(['key' => '{{linkedin_access_token}}', 'value' => null, 'secret' => true, 'description' => $row['description'] ?? null, 'needs_reentry' => true], $row);

        // Saving the environment unchanged (the write-only secret field is empty) keeps the payload byte-identical.
        $crawler = $this->client->request('GET', $this->environmentUrl('/edit', $environmentId));
        self::assertStringContainsString('cannot be decrypted with the current key', (string) $this->client->getResponse()->getContent());
        $this->client->submit($crawler->filter('form[name="api_environment_form"]')->form());
        self::assertResponseRedirects();
        self::assertSame('nowo_as_enc_v1:placeholder-corrupt', $this->storedValue((int) $variable->getId()));

        // Unchecking "Secret" is refused until the value is entered again.
        $crawler = $this->client->request('GET', $this->environmentUrl('/edit', $environmentId));
        $form    = $crawler->filter('form[name="api_environment_form"]')->form();
        foreach ($form->all() as $name => $field) {
            if ($field instanceof ChoiceFormField && str_ends_with($name, '[secret]') && $this->isLinkedInRow($form->getValues(), $name)) {
                $field->untick();
            }
        }
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('enter its value again before you uncheck', (string) $this->client->getResponse()->getContent());
        self::assertSame('nowo_as_enc_v1:placeholder-corrupt', $this->storedValue((int) $variable->getId()));
    }

    /**
     * Replaces the stored value of the demo's secret LinkedIn token with a payload no key opens.
     *
     * @return array{ApiEnvironmentVariable, ApiEndpoint}
     */
    private function corruptSecret(): array
    {
        $em       = $this->entityManager();
        $variable = $em->getRepository(ApiEnvironmentVariable::class)->findOneBy(['variableKey' => 'linkedin_access_token']);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $variable);
        $table = $em->getClassMetadata(ApiEnvironmentVariable::class)->getTableName();
        $em->getConnection()->executeStatement('UPDATE ' . $table . ' SET value = ? WHERE id = ?', ['nowo_as_enc_v1:placeholder-corrupt', $variable->getId()]);
        $em->clear();

        $endpoint = null;
        foreach ($em->getRepository(ApiEndpoint::class)->findAll() as $candidate) {
            $service = $candidate->getService();
            if ($service !== null && in_array('linkedin_access_token', RequestExecutor::referencedVariables($candidate, $service), true)) {
                $endpoint = $candidate;

                break;
            }
        }
        self::assertInstanceOf(ApiEndpoint::class, $endpoint);
        $variable = $em->getRepository(ApiEnvironmentVariable::class)->findOneBy(['variableKey' => 'linkedin_access_token']);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $variable);
        self::assertTrue($variable->isUnreadable());
        $em->clear();

        return [$variable, $endpoint];
    }

    private function storedValue(int $id): string
    {
        $em    = $this->entityManager();
        $table = $em->getClassMetadata(ApiEnvironmentVariable::class)->getTableName();

        return (string) $em->getConnection()->fetchOne('SELECT value FROM ' . $table . ' WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function isLinkedInRow(array $values, string $secretField): bool
    {
        $keyField = substr($secretField, 0, -strlen('[secret]')) . '[variableKey]';

        return ($values[$keyField] ?? null) === '{{linkedin_access_token}}';
    }
}
