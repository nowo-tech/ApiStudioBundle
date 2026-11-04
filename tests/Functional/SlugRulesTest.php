<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

/**
 * 1.11: an empty slug is derived from the name; a new or edited slug must have the documented shape; a legacy slug
 * of another shape does not block editing other fields.
 */
final class SlugRulesTest extends StudioWebTestCase
{
    public function testEmptySlugIsDerivedFromTheName(): void
    {
        $crawler = $this->client->request('GET', '/api-studio/workspaces/new');
        self::assertSame('', $crawler->filter('#api_workspace_form_slug')->attr('value') ?? '');
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Billing API',
            'api_workspace_form[slug]' => '',
        ]));

        self::assertResponseRedirects();
        self::assertInstanceOf(ApiWorkspace::class, $this->entityManager()->getRepository(ApiWorkspace::class)->findOneBy(['slug' => 'billing_api']));
    }

    public function testInvalidNewSlugIs422(): void
    {
        $crawler = $this->client->request('GET', '/api-studio/workspaces/new');
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Billing',
            'api_workspace_form[slug]' => 'Bad Slug!',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('may only contain lowercase letters', (string) $this->client->getResponse()->getContent());
    }

    public function testLegacySlugDoesNotBlockOtherEdits(): void
    {
        $em     = $this->entityManager();
        $legacy = new ApiWorkspace('Legacy', 'Legacy Slug');
        $em->persist($legacy);
        $em->flush();
        $id = (int) $legacy->getId();
        $em->clear();

        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit', $id));
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form(['api_workspace_form[name]' => 'Legacy renamed']));
        self::assertResponseRedirects();

        $crawler = $this->client->request('GET', $this->workspaceUrl('/edit', $id));
        $this->client->submit($crawler->filter('form[name="api_workspace_form"]')->form(['api_workspace_form[slug]' => 'Still Bad']));
        self::assertResponseStatusCodeSame(422);
    }
}
