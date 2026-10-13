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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
