<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;
use Nowo\ApiStudioBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(ApiWorkspace::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(ApiWorkspace::class);
        $driver->loadMetadataForClass(ApiWorkspace::class, new ClassMetadata(ApiWorkspace::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the Api Studio entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(ApiWorkspace::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), ApiStudioEntityManagerPass::ENTITY_NAMESPACE);

        self::assertTrue($chain->isTransient(ApiWorkspace::class));
        self::assertTrue($chain->isTransient(ApiService::class));
        self::assertFalse($chain->isTransient(self::class), 'other classes still go to the default driver');
    }
}
