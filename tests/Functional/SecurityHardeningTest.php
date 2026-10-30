<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Symfony\Component\DomCrawler\Field\InputFormField;

use function json_decode;

/**
 * BW audit L1 / I2: secrets stay on the server (console maps, edit form, no-store), the workspace delete is
 * type-to-confirm on the server too.
 */
final class SecurityHardeningTest extends StudioWebTestCase
{
    private const SECRET = 'tok-FUNCTIONAL-SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        $environment = $this->entityManager()->getRepository(ApiEnvironment::class)->find($this->ids['environment']);
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        $environment->addVariable((new ApiEnvironmentVariable('hardeningToken', self::SECRET))->setSecret(true));
        $environment->addVariable(new ApiEnvironmentVariable('hardeningPlain', 'visible-value'));
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    public function testConsoleNeverReceivesSecretValues(): void
    {
        $crawler = $this->client->request('GET', $this->endpointUrl());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::SECRET, $html);

        $maps = json_decode((string) $crawler->filter('#api-studio-tester')->attr('data-environment-maps'), true);
        self::assertIsArray($maps);
        $variables = $maps[$this->ids['environment']]['variables'] ?? null;
        self::assertIsArray($variables);
        self::assertSame('{{hardeningToken}}', $variables['hardeningToken']);
        self::assertSame('visible-value', $variables['hardeningPlain']);
    }

    public function testApiStudioResponsesAreNotCached(): void
    {
        foreach ([$this->endpointUrl(), $this->environmentUrl(), $this->environmentUrl('/edit'), '/api-studio/'] as $url) {
            $this->client->request('GET', $url);
            $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cacheControl, $url);
            self::assertStringContainsString('private', $cacheControl, $url);
        }
    }

    public function testSecretValueIsWriteOnlyInTheEditForm(): void
    {
        $crawler = $this->client->request('GET', $this->environmentUrl('/edit'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());

        $passwords = $crawler->filter('input[type="password"][name$="[value]"]');
        self::assertGreaterThan(0, $passwords->count());

        // Saving with the empty password field keeps the stored secret.
        $form = $crawler->filter('form[name="api_environment_form"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $environment = $this->entityManager()->getRepository(ApiEnvironment::class)->find($this->ids['environment']);
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        self::assertSame(self::SECRET, $environment->getVariableMap()['hardeningToken'] ?? null);
        self::assertSame('visible-value', $environment->getVariableMap()['hardeningPlain'] ?? null);
    }

    public function testWorkspaceDeleteNeedsTheTypedName(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit'));
        self::assertCount(1, $crawler->filter('dialog[data-nowo-ui-confirm] input[data-nowo-confirm-expected]'));
        $form         = $crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form();
        $confirmation = $form['confirmation'];
        self::assertInstanceOf(InputFormField::class, $confirmation);

        $confirmation->setValue('not the name');
        $this->client->submit($form);
        self::assertResponseRedirects($this->workspaceUrl('/edit'));
        $this->client->followRedirect();
        self::assertStringContainsString('Workspace was not deleted', $this->flashText());
        self::assertInstanceOf(ApiWorkspace::class, $this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['workspace']));

        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit'));
        $this->client->submit($crawler->filter('dialog[data-nowo-ui-confirm] form[action$="/delete"]')->form());
        self::assertResponseRedirects('/api-studio/workspaces');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(ApiWorkspace::class)->find($this->ids['workspace']));
    }
}
