<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Doctrine\RowVersionListener;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Service\FolderTree;
use Nowo\ApiStudioBundle\Tests\Database\Support\SecondConnection;
use Nowo\ApiStudioBundle\Tests\Support\RowVersionEditProbe;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;

use function getenv;
use function is_string;
use function sprintf;

/**
 * 1.23 REQ-RUNTIME-003 rule 4 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the folder edit form saves only
 * on the row version it was rendered from. Two editors racing on the same version (exactly one saves, the other gets a
 * stale edit and writes nothing), a tree move (an ORM write without a version) moving the version atomically, and the
 * edit's rule checks ({@see FolderTree::guardEdit()}) running under the service lock before the claim. A second session
 * in its own process holds the locks. Runs only when AS_MYSQL_URL, AS_MARIADB_URL and AS_POSTGRES_URL are set (CI job
 * "Databases", `make test-with-db`); the job fails on any skipped test.
 */
final class FolderRowVersionRaceTest extends TestCase
{
    use ScopeOrmTrait;

    private Connection $connection;

    private EntityManagerInterface $manager;

    private ?SecondConnection $other = null;

    private string $url = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function servers(): iterable
    {
        yield 'MySQL 8.4' => ['AS_MYSQL_URL'];
        yield 'MariaDB 11.4' => ['AS_MARIADB_URL'];
        yield 'PostgreSQL 16' => ['AS_POSTGRES_URL'];
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->manager)) {
            // The folder table references the service table: drop it here, or the other suites cannot rebuild theirs.
            $this->other = null;
            (new SchemaTool($this->manager))->dropSchema($this->manager->getMetadataFactory()->getAllMetadata());
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    #[DataProvider('servers')]
    public function testTheSecondFolderEditorOnTheSameVersionWaitsThenWritesNothing(string $variable): void
    {
        $this->boot($variable);
        $folder = $this->folder();
        $id     = (int) $folder->getId();
        self::assertSame(1, $folder->getRowVersion());

        // Editor A (second session) saves its form rendered at version 1 and holds the row lock until the COMMIT.
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        self::assertSame('OK', $other->execute(sprintf("UPDATE api_studio_folder SET row_version = row_version + 1, name = 'Editor A' WHERE id = %d AND row_version = 1", $id)));
        $other->sleep(700);
        $other->send('COMMIT');

        // Editor B, also rendered at version 1: the service lock and the rule checks pass, then its claim waits for A and matches no row.
        $folder->setName('Editor B');
        try {
            $this->save($folder, 1);
            self::fail('Expected a stale edit.');
        } catch (StaleEditException) {
        }
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->await());

        self::assertSame('Editor A', $this->connection->fetchOne('SELECT name FROM api_studio_folder WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_folder WHERE id = ?', [$id]));
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testTheFirstFolderEditorSavesAndTheSecondMatchesNoRow(string $variable): void
    {
        $this->boot($variable);
        $folder = $this->folder();
        $id     = (int) $folder->getId();

        // Editor A (this session) claims and flushes, holding the lock; editor B's guarded UPDATE waits for it.
        $folder->setName('Editor A');
        $this->connection->beginTransaction();
        RowVersionGuard::claim($this->manager, $folder, 1);
        $this->manager->flush();
        $other = $this->other();
        self::assertSame('OK', $other->execute($this->begin()));
        $other->send(sprintf("UPDATE api_studio_folder SET row_version = row_version + 1, name = 'Editor B' WHERE id = %d AND row_version = 1", $id));
        $this->connection->commit();
        self::assertSame('OK', $other->await());
        self::assertSame('OK', $other->execute('COMMIT'));

        // B's statement re-read the row after A's commit (MySQL / MariaDB current read, PostgreSQL re-check): no lost update.
        self::assertSame('Editor A', $this->connection->fetchOne('SELECT name FROM api_studio_folder WHERE id = ?', [$id]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_folder WHERE id = ?', [$id]));
        self::assertSame(2, $folder->getRowVersion());
        $this->assertUsable();
    }

    #[DataProvider('servers')]
    public function testATreeMoveMovesTheVersionSoAnOlderEditFormIsRefused(string $variable): void
    {
        $this->boot($variable);
        $folder = $this->folder();
        $target = $this->folder('Target');
        $tree   = new FolderTree($this->manager, new ApiFolderRepository($this->managerRegistry()), 8);

        // A drag & drop of the tree (no version in the request) after the edit form was rendered at version 1.
        $tree->moveFolder($folder, $target);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT row_version FROM api_studio_folder WHERE id = ?', [$folder->getId()]));

        $this->manager->clear();
        $stale = $this->manager->find(ApiFolder::class, $folder->getId());
        self::assertInstanceOf(ApiFolder::class, $stale);
        $stale->setName('From the older form');
        try {
            $this->save($stale, 1, static fn () => $tree->guardEdit($stale));
            self::fail('Expected a stale edit.');
        } catch (StaleEditException) {
        }
        self::assertSame('Folder', $this->connection->fetchOne('SELECT name FROM api_studio_folder WHERE id = ?', [$folder->getId()]));
        $this->assertUsable();
    }

    private function boot(string $variable): void
    {
        $url = getenv($variable);
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', $variable));
        }

        $this->url        = $url;
        $parser           = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url), $this->scopeConfiguration());
        // Folders mapped (the scope helper's manager leaves ApiFolder a mapped superclass).
        $this->manager = new EntityManager($this->connection, $this->scopeConfiguration(), new EventManager());
        $this->manager->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', true, false, true, false, true));
        $this->manager->getEventManager()->addEventListener([Events::postUpdate, Events::postPersist, Events::postRemove], new RowVersionListener());
        $this->createScopeSchema($this->manager);
        // Bounded waits: a test that blocks fails within seconds instead of hanging the job.
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '15s'" : 'SET SESSION innodb_lock_wait_timeout = 15');
    }

    private function folder(string $name = 'Folder'): ApiFolder
    {
        $service = $this->manager->createQueryBuilder()->select('s')->from(ApiService::class, 's')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (!$service instanceof ApiService) {
            $workspace = new ApiWorkspace('Insurers', 'insurers');
            $service   = (new ApiService('Mapfre', 'mapfre'))->setWorkspace($workspace);
            $this->manager->persist($workspace);
            $this->manager->persist($service);
        }
        $folder = new ApiFolder($name);
        $folder->setService($service);
        $this->manager->persist($folder);
        $this->manager->flush();

        return $folder;
    }

    private function managerRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->manager);

        return $registry;
    }

    /**
     * What the folder edit page does (RowVersionEditTrait::saveEdit() with the rule checks as the first step).
     */
    private function save(ApiFolder $folder, int $expected, ?callable $beforeClaim = null): bool
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $folder, ['data_class' => ApiFolder::class]);
        RowVersionField::add($builder, [RowVersionField::OPTION => $expected]);
        $form = $builder->getForm();
        $form->submit([RowVersionField::NAME => (string) $expected]);

        return $this->probe()->save($form, $folder, $beforeClaim);
    }

    private function probe(): RowVersionEditProbe
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(new ConcurrencyConflictSubscriber());

        return new RowVersionEditProbe($this->manager, $container);
    }

    private function other(): SecondConnection
    {
        return $this->other = new SecondConnection($this->url);
    }

    private function begin(): string
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BEGIN' : 'START TRANSACTION';
    }

    private function assertUsable(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
        self::assertTrue($this->manager->isOpen());
    }
}
