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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function sprintf;

/**
 * Functional test base: the test kernel with a freshly seeded SQLite database (DemoSeedService) and a second, empty
 * workspace ("other") for the cross-workspace 404 checks.
 */
abstract class StudioWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    /** @var array{workspace: int, service: int, endpoint: int, environment: int, other: int} */
    protected array $ids;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->catchExceptions(false);

        $entityManager = $this->entityManager();
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var DemoSeedService $seeder */
        $seeder = self::getContainer()->get(DemoSeedService::class);
        $seeder->seed();
        $other = new ApiWorkspace('Other workspace', 'other');
        $entityManager->persist($other);
        $entityManager->flush();
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
            'other'       => (int) $other->getId(),
        ];
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    protected function workspaceUrl(string $suffix = '', ?int $workspace = null): string
    {
        return sprintf('/api-studio/workspaces/%d%s', $workspace ?? $this->ids['workspace'], $suffix);
    }

    protected function serviceUrl(string $suffix = '', ?int $service = null): string
    {
        return $this->workspaceUrl(sprintf('/services/%d%s', $service ?? $this->ids['service'], $suffix));
    }

    protected function endpointUrl(string $suffix = '', ?int $endpoint = null): string
    {
        return $this->serviceUrl(sprintf('/endpoints/%d%s', $endpoint ?? $this->ids['endpoint'], $suffix));
    }

    protected function environmentUrl(string $suffix = '', ?int $environment = null): string
    {
        return $this->workspaceUrl(sprintf('/environments/%d%s', $environment ?? $this->ids['environment'], $suffix));
    }

    /**
     * Text of the flash messages rendered by the UiKit layout on the current page.
     */
    protected function flashText(): string
    {
        $flashes = $this->client->getCrawler()->filter('.nowo-ui-flash');

        return $flashes->count() > 0 ? $flashes->text() : '';
    }
}
