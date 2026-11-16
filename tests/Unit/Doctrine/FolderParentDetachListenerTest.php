<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\FolderParentDetachListener;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_keys;
use function dirname;
use function extension_loaded;
use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.14 audit RJ-D-M1: deleting a service / workspace unsets the folder parents inside the flush transaction (entity
 * change sets, no SQL from onFlush), so a failed delete keeps the tree as it was.
 */
final class FolderParentDetachListenerTest extends TestCase
{
    private EntityManager $em;

    private InMemoryLogger $sql;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $this->sql = new InMemoryLogger();
        $config->setMiddlewares([new Middleware($this->sql)]);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        $events   = $this->em->getEventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', false, false, true));
        $events->addEventListener(Events::onFlush, new FolderParentDetachListener());
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
    }

    public function testServiceDeleteUnsetsParentsInsideTheFlushTransaction(): void
    {
        [$service]          = $this->tree();
        $this->sql->records = [];

        $this->em->remove($service);
        $this->em->flush();

        $messages = array_map(static fn (array $record): string => self::statement($record), $this->records());
        $begin    = self::indexOf($messages, static fn (string $m): bool => str_starts_with($m, 'Beginning transaction'));
        $update   = self::indexOf($messages, static fn (string $m): bool => str_contains($m, 'UPDATE api_studio_folder'));
        self::assertNotNull($begin);
        self::assertNotNull($update, 'the parent links are unset by the flush itself');
        self::assertGreaterThan($begin, $update, 'no autocommitted statement before the flush transaction');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_folder'));
    }

    public function testAFailedDeleteKeepsTheParents(): void
    {
        [$service, , $child] = $this->tree();
        $this->em->getConnection()->executeStatement("CREATE TRIGGER no_service_delete BEFORE DELETE ON api_studio_service BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $this->em->remove($service);
        try {
            $this->em->flush();
            self::fail('The delete should fail.');
        } catch (Throwable $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }

        $parent = $this->em->getConnection()->fetchOne('SELECT parent_id FROM api_studio_folder WHERE id = ?', [$child->getId()]);
        self::assertNotNull($parent, 'the failed delete left the tree as it was');
    }

    public function testWorkspaceDeleteAndUnrelatedFlushes(): void
    {
        [$service] = $this->tree();
        $workspace = $service->getWorkspace();
        self::assertInstanceOf(ApiWorkspace::class, $workspace);

        $service->setDescription('unrelated change');
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_folder WHERE parent_id IS NOT NULL'));

        $this->em->remove($workspace);
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_folder'));
    }

    /**
     * @return array{ApiService, ApiFolder, ApiFolder}
     */
    private function tree(): array
    {
        $workspace = new ApiWorkspace('W', 'w');
        $service   = new ApiService('S', 's');
        $workspace->addService($service);
        $top   = (new ApiFolder('Top'))->setService($service);
        $child = (new ApiFolder('Child'))->setService($service)->setParent($top);
        $this->em->persist($workspace);
        $this->em->persist($top);
        $this->em->persist($child);
        $this->em->flush();

        return [$service, $top, $child];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    private function records(): array
    {
        return $this->sql->records;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function statement(array $record): string
    {
        $context = $record['context'] ?? [];
        $sql     = is_array($context) && is_string($context['sql'] ?? null) ? ' ' . $context['sql'] : '';

        return (string) ($record['message'] ?? '') . $sql;
    }

    /**
     * @param list<string> $messages
     * @param callable(string): bool $match
     */
    private static function indexOf(array $messages, callable $match): ?int
    {
        foreach (array_keys($messages) as $index) {
            if ($match($messages[$index])) {
                return $index;
            }
        }

        return null;
    }
}
