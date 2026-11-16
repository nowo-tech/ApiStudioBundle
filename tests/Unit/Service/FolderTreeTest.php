<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use DomainException;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Service\FolderTree;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.14 folder tree rules on a real (SQLite) schema: depth with subfolders, other services, deletes moving content up,
 * rollback on failure.
 */
final class FolderTreeTest extends TestCase
{
    private EntityManager $em;

    private ApiFolderRepository $folders;

    private ApiService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        $this->em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', false, false, true));
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->folders = new ApiFolderRepository($registry);

        $workspace     = new ApiWorkspace('W', 'w');
        $this->service = new ApiService('S', 's');
        $workspace->addService($this->service);
        $this->em->persist($workspace);
        $this->em->flush();
    }

    public function testDepthCountsTheSubfoldersOfTheMovedFolder(): void
    {
        $tree = new FolderTree($this->em, $this->folders, 3);
        $a    = $this->folder('A');
        $b    = $this->folder('B', $a);
        $this->folder('C', $b);
        $x = $this->folder('X');

        $tree->moveFolder($a, null);
        try {
            $tree->moveFolder($a, $x);
            self::fail('A has two levels below it: under X it would be 4 deep.');
        } catch (DomainException $e) {
            self::assertSame(FolderTree::ERROR_DEPTH, $e->getMessage());
        }

        $tree->moveFolder($x, $a, $b->getId());
        self::assertSame($a, $x->getParent());
        self::assertSame(3, $tree->maxDepth());
        self::assertLessThan($b->getSortOrder(), $x->getSortOrder());
    }

    public function testFoldersAndEndpointsStayInTheirService(): void
    {
        $tree  = new FolderTree($this->em, $this->folders);
        $other = new ApiService('Other', 'other');
        $this->service->getWorkspace()?->addService($other);
        $this->em->persist($other);
        $foreign = (new ApiFolder('Foreign'))->setService($other);
        $this->em->persist($foreign);
        $mine     = $this->folder('Mine');
        $endpoint = $this->endpoint('E');

        foreach ([
            static fn () => $tree->moveFolder($mine, $foreign),
            static fn () => $tree->moveEndpoint($endpoint, $foreign),
            static fn () => $tree->moveFolder(new ApiFolder('Orphan'), null),
            static fn () => $tree->moveEndpoint(new ApiEndpoint('Orphan', 'orphan'), null),
        ] as $move) {
            try {
                $move();
                self::fail('Expected a refusal.');
            } catch (DomainException $e) {
                self::assertSame(FolderTree::ERROR_OTHER_SERVICE, $e->getMessage());
            }
        }

        $tree->deleteFolder(new ApiFolder('Unsaved orphan'));
        self::assertSame(0, FolderTree::nextSortOrder([]));
        self::assertSame(30, FolderTree::nextSortOrder([(new ApiFolder('a'))->setSortOrder(20), (new ApiFolder('b'))->setSortOrder(5)]));
    }

    public function testDeleteMovesSubfoldersAndEndpointsUp(): void
    {
        $tree   = new FolderTree($this->em, $this->folders);
        $top    = $this->folder('Top');
        $middle = $this->folder('Middle', $top);
        $this->folder('Leaf', $middle);
        $inside = $this->endpoint('Inside', $middle);
        $this->endpoint('Sibling', $top);

        $tree->deleteFolder($middle);
        $this->em->clear();

        $leaf = $this->folders->findOneBy(['name' => 'Leaf']);
        self::assertSame('Top', $leaf?->getParent()?->getName());
        self::assertNull($this->folders->findOneBy(['name' => 'Middle']));
        $moved = $this->em->find(ApiEndpoint::class, $inside->getId());
        self::assertSame('Top', $moved?->getFolder()?->getName());
        self::assertSame(10, $moved->getSortOrder(), 'after the endpoint already there');
    }

    public function testAFailedWriteRollsBack(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(4))->method('beginTransaction');
        $rollbacks = new ArrayObject();
        $em->expects(self::exactly(4))->method('rollback')->willReturnCallback(static function () use ($rollbacks): void {
            $rollbacks->append(true);
            if ($rollbacks->count() === 4) {
                throw new RuntimeException('savepoint gone');
            }
        });
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('close');
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::never())->method('commit');
        $em->method('flush')->willThrowException(new RuntimeException('down'));
        $repository = $this->createStub(ApiFolderRepository::class);
        $repository->method('children')->willReturn([]);
        $repository->method('endpointsIn')->willReturn([]);
        $repository->method('treeRows')->willReturn([['id' => 5, 'name' => 'F', 'parent' => null, 'service' => (int) $this->service->getId(), 'workspace' => 1, 'sortOrder' => 0, 'createdBy' => null]]);
        $tree = new FolderTree($em, $repository);

        $folder = (new ApiFolder('F'))->setService($this->service);
        (new ReflectionProperty(ApiFolder::class, 'id'))->setValue($folder, 5);
        $endpoint = (new ApiEndpoint('E', 'e'))->setService($this->service);
        // The fourth rollback fails (MySQL after a deadlock): the connection is closed and the first error surfaces.
        foreach ([static fn () => $tree->moveFolder($folder, null), static fn () => $tree->moveEndpoint($endpoint, null), static fn () => $tree->deleteFolder($folder), static fn () => $tree->saveFolder($folder)] as $write) {
            try {
                $write();
                self::fail('Expected the flush error.');
            } catch (RuntimeException $e) {
                self::assertSame('down', $e->getMessage());
            }
        }
    }

    private function folder(string $name, ?ApiFolder $parent = null): ApiFolder
    {
        $folder = (new ApiFolder($name))->setService($this->service)->setParent($parent);
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    private function endpoint(string $name, ?ApiFolder $folder = null): ApiEndpoint
    {
        $endpoint = (new ApiEndpoint($name, strtolower($name)))->setFolder($folder);
        $this->service->addEndpoint($endpoint);
        $this->em->persist($endpoint);
        $this->em->flush();

        return $endpoint;
    }
}
