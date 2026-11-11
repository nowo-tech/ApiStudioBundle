<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\TestCase;

final class ApiStudioEntityManagerResolverTest extends TestCase
{
    public function testWithoutANameTheManagerOfTheEntitiesIsUsed(): void
    {
        $manager  = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(ApiWorkspace::class)->willReturn($manager);
        $registry->expects(self::never())->method('getManager');

        self::assertSame($manager, (new ApiStudioEntityManagerResolver($registry))->entityManager());
    }

    public function testTheConfiguredManagerIsUsedByName(): void
    {
        $manager  = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('api_studio')->willReturn($manager);
        $registry->expects(self::never())->method('getManagerForClass');

        $resolver = new ApiStudioEntityManagerResolver($registry, 'api_studio');
        self::assertSame($manager, $resolver->entityManager());
        self::assertSame('api_studio', $resolver->managerName());
    }

    public function testNoOrmManagerIsAClearError(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->createStub(ObjectManager::class));

        $resolver = new ApiStudioEntityManagerResolver($registry);
        self::assertNull($resolver->find());
        self::assertNull($resolver->managerName());
        $resolver->resetIfClosed();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager maps');
        $resolver->entityManager();
    }

    public function testOnlyTheClosedBundleManagerIsReset(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('api_studio')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('api_studio');

        (new ApiStudioEntityManagerResolver($registry, 'api_studio'))->resetIfClosed();
    }

    public function testUnnamedManagerIsNotReset(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($this->createStub(EntityManagerInterface::class));
        $registry->expects(self::never())->method('resetManager');

        (new ApiStudioEntityManagerResolver($registry))->resetIfClosed();
    }
}
