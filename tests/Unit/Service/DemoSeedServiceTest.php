<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\DemoSeedService;
use PHPUnit\Framework\TestCase;

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
}
