<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\DemoSeedService;
use Nowo\ApiStudioBundle\Soap\SoapEnvelope;
use PHPUnit\Framework\TestCase;

use function array_map;
use function preg_match_all;

final class DemoSeedServiceTest extends TestCase
{
    public function testSeedPersistsMultipleReferenceServices(): void
    {
        $workspaceRepository = $this->createMock(ApiWorkspaceRepository::class);
        $workspaceRepository->method('findOneBy')->willReturn(null);
        $workspaceRepository->method('findAll')->willReturn([]);

        $serviceRepository = $this->createMock(ApiServiceRepository::class);
        $serviceRepository->method('findOneBy')->willReturn(null);

        $persistedServices = [];
        $entityManager     = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persistedServices): void {
            if ($entity instanceof ApiService) {
                $persistedServices[] = $entity->getSlug();
            }
        });
        $entityManager->expects(self::once())->method('flush');

        $seedService = new DemoSeedService($entityManager, $workspaceRepository, $serviceRepository);
        $seedService->seed();

        self::assertContains('jsonplaceholder', $persistedServices);
        self::assertContains('linkedin', $persistedServices);
        self::assertContains('google_translate', $persistedServices);
        self::assertContains('catastro_soap', $persistedServices);
        self::assertContains('catastro_rest', $persistedServices);
    }

    public function testSeededSoapEndpointsAreRawEnvelopesWithResolvableVariables(): void
    {
        $workspaceRepository = $this->createMock(ApiWorkspaceRepository::class);
        $workspaceRepository->method('findOneBy')->willReturn(null);
        $serviceRepository = $this->createMock(ApiServiceRepository::class);
        $serviceRepository->method('findOneBy')->willReturn(null);

        $workspace     = null;
        $endpoints     = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$workspace, &$endpoints): void {
            if ($entity instanceof ApiWorkspace) {
                $workspace = $entity;
            }
            if ($entity instanceof ApiEndpoint) {
                $endpoints[] = $entity;
            }
        });

        (new DemoSeedService($entityManager, $workspaceRepository, $serviceRepository))->seed();

        self::assertInstanceOf(ApiWorkspace::class, $workspace);
        $environment = $workspace->getEnvironments()->first();
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        $variables = $environment->getVariableMap();

        $soapActions = [];
        foreach ($endpoints as $endpoint) {
            $service = $endpoint->getService();
            self::assertInstanceOf(ApiService::class, $service);
            if ($service->getProtocol() !== ApiProtocol::Soap) {
                continue;
            }

            $body = (string) $endpoint->getRequestBodyTemplate();
            self::assertTrue(SoapEnvelope::isEnvelope($body), $endpoint->getSlug() . ' must use raw-envelope mode.');
            self::assertStringStartsWith('http://tempuri.org/OVCServWeb/', (string) $endpoint->getSoapAction(), 'ASMX needs the full SOAPAction URI.');
            $soapActions[] = SoapEnvelope::operationName((string) $endpoint->getSoapAction());

            preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $service->getBaseUrl() . $body, $matches);
            foreach ($matches[1] as $name) {
                self::assertArrayHasKey($name, $variables, 'Seeded variable missing for {{' . $name . '}}.');
            }
        }

        self::assertSame(['Consulta_CPMRC', 'Consulta_RCCOOR', 'Consulta_DNPRC'], $soapActions);
    }

    public function testReseedAddsMissingReferenceVariablesWithoutOverwriting(): void
    {
        $workspace   = new ApiWorkspace('APIs de referencia', 'demo');
        $environment = new ApiEnvironment('Sandbox', 'sandbox');
        $environment->addVariable(new ApiEnvironmentVariable('catastro_srs', 'EPSG:25830'));
        $workspace->addEnvironment($environment);

        $workspaceRepository = $this->createMock(ApiWorkspaceRepository::class);
        $workspaceRepository->method('findOneBy')->willReturn($workspace);
        $serviceRepository = $this->createMock(ApiServiceRepository::class);
        $serviceRepository->method('findOneBy')->willReturn(null);

        (new DemoSeedService($this->createMock(EntityManagerInterface::class), $workspaceRepository, $serviceRepository))->seed();

        $variables = $environment->getVariableMap();
        self::assertSame('EPSG:25830', $variables['catastro_srs'] ?? null);
        self::assertArrayHasKey('catastro_callejero_wsdl', $variables);
        self::assertCount(1, array_map(static fn (ApiEnvironment $e): string => $e->getSlug(), $workspace->getEnvironments()->toArray()));
    }
}
