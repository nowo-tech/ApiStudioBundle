<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Command;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\ApiStudioBundle\Command\SyncSchemaCommand;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

final class SyncSchemaCommandTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testCreatesTablesThenReportsUpToDate(): void
    {
        $em     = $this->createEntityManager(false);
        $tester = new CommandTester(new SyncSchemaCommand($this->createRegistry($em), 'default'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/Executed \d+ SQL statement\(s\)\./', $display);
        // On an empty database the create and update passes both emit CREATE TABLE: the second ones are skipped.
        self::assertStringContainsString('already applied', $display);
        self::assertContains('workspace', $em->getConnection()->createSchemaManager()->listTableNames());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('already up to date', $tester->getDisplay());
    }

    public function testRejectsNonOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection "reports" is not an ORM entity manager.');

        (new CommandTester(new SyncSchemaCommand($registry, 'reports')))->execute([]);
    }

    public function testRethrowsUnexpectedDatabaseErrors(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $config->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(#[SensitiveParameter] array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function exec(string $sql): int|string
                            {
                                throw new RuntimeException('disk I/O error');
                            }
                        };
                    }
                };
            }
        }]);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk I/O error');

        (new CommandTester(new SyncSchemaCommand($this->createRegistry($em), 'default')))->execute([]);
    }
}
