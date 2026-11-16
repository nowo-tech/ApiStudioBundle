<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

/**
 * 1.14 folders off (default): no folder routes, no folder markup, 1.13 pages.
 */
final class FoldersOffTest extends StudioWebTestCase
{
    public function testFolderRoutesAnswer404AndPagesHaveNoFolders(): void
    {
        $this->client->catchExceptions(true);
        $this->client->request('GET', $this->serviceUrl('/folders/new'));
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', $this->serviceUrl());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="api-studio-service-folders"], [data-tree-root], [data-testid="api-studio-new-folder"]'));

        $crawler = $this->client->request('GET', $this->endpointUrl('/edit'));
        self::assertCount(0, $crawler->filter('select[name="api_endpoint_form[folder]"]'));
    }
}
