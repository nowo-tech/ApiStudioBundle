<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tests\Functional\App\LegacyLayoutTestKernel;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function is_string;

/**
 * A pre-1.10 host layout (renders {% block body %}) keeps working through the deprecated bridge
 * (_legacy_layout.html.twig): page header, flashes, content and assets end up in the host blocks.
 */
#[IgnoreDeprecations]
final class LegacyLayoutBridgeTest extends WebTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $layout = $options['layout'] ?? 'legacy_layout.html.twig';

        return new LegacyLayoutTestKernel('test', true, is_string($layout) ? $layout : 'legacy_layout.html.twig');
    }

    public function testPagesRenderInsideThePreTenLayout(): void
    {
        $this->expectUserDeprecationMessageMatches('/The layout "legacy_layout\.html\.twig" does not render the UiKit canonical blocks/');
        $client = $this->client('legacy_layout.html.twig');

        $crawler = $client->request('GET', '/api-studio/workspaces');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('body.legacy-host main#legacy-host-main [data-testid="api-studio-workspaces"]'));
        self::assertCount(1, $crawler->filter('link[href="/host.css"]'));
        self::assertGreaterThan(0, $crawler->filter('link[href$="api-studio-theme.css"]')->count());
        self::assertGreaterThan(0, $crawler->filter('script[src$="nowo-ui-dialog.js"]')->count());
        self::assertStringContainsString('· Legacy host', $crawler->filter('title')->text());
    }

    public function testCanonicalBlocksOfTheHostLayoutAreNotPrintedTwice(): void
    {
        $this->expectUserDeprecationMessageMatches('/does not render the UiKit canonical blocks/');
        $client = $this->client('legacy_layout_with_flashes.html.twig');

        $client->request('GET', '/api-studio/workspaces/new');
        $client->submit($client->getCrawler()->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Legacy',
            'api_workspace_form[slug]' => 'legacy',
        ]));
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Created successfully.', $crawler->filter('#host-flashes')->text());
        self::assertCount(1, $crawler->filter('script[src$="nowo-ui-dialog.js"]'));
    }

    private function client(string $layout): KernelBrowser
    {
        $client = self::createClient(['layout' => $layout]);
        $client->catchExceptions(false);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $entityManager->persist(new ApiWorkspace('Existing', 'existing'));
        $entityManager->flush();

        return $client;
    }
}
