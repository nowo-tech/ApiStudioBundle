<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Doctrine\WorkspaceImportUnit;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 1.21.1: an import is one write unit under the workspace lock (SQLite here; the race is in
 * tests/Database/RowVersionRaceTest.php): it commits the import's rows, rolls back only its own levels on a failure,
 * and tells the import page which failures are worth a retry.
 */
final class WorkspaceImportUnitTest extends TestCase
{
    use ScopeOrmTrait;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->manager = $this->scopeEntityManager($this->sqliteScopeRegistry());
    }

    public function testTheImportIsCommittedAndItsResultReturned(): void
    {
        [$workspace, $service] = $this->graph();

        $environment = new ApiEnvironment('Imported', 'imported');
        $result      = WorkspaceImportUnit::run($this->manager, $workspace, $service, function () use ($workspace, $environment): ApiEnvironment {
            $workspace->addEnvironment($environment);
            $this->manager->persist($environment);

            return $environment;
        });

        self::assertNotNull($result->getId());
        self::assertSame(0, $this->manager->getConnection()->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne("SELECT COUNT(*) FROM api_studio_environment WHERE slug = 'imported'"));
    }

    public function testAFailureRollsBackOnlyItsOwnLevelAndIsRethrown(): void
    {
        [$workspace] = $this->graph();
        $connection  = $this->manager->getConnection();
        $connection->beginTransaction(); // A caller's transaction stays the caller's.
        $failure = new RuntimeException('Broken file.');
        $caught  = null;

        try {
            WorkspaceImportUnit::run($this->manager, $workspace, null, static function () use ($connection, $failure): void {
                $connection->executeStatement("UPDATE api_studio_workspace SET name = 'Half imported'");

                throw $failure;
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($failure, $caught);

        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame('Insurers', $connection->fetchOne('SELECT name FROM api_studio_workspace WHERE id = ?', [$workspace->getId()]));
        $connection->rollBack();
    }

    public function testLockConflictsAndConcurrentUniqueRowsAreRetryable(): void
    {
        self::assertTrue(WorkspaceImportUnit::isRetryable(new DeadlockException(self::driver('Deadlock found', 1213), null)));
        self::assertTrue(WorkspaceImportUnit::isRetryable(new LockWaitTimeoutException(self::driver('Lock wait timeout exceeded', 1205), null)));
        self::assertTrue(WorkspaceImportUnit::isRetryable(new DriverException(self::driver('SAVEPOINT DOCTRINE_2 does not exist', 1305), null)));
        $unique = new UniqueConstraintViolationException(self::driver('Duplicate entry for key uniq_environment_workspace_slug', 1062), null);
        self::assertTrue(WorkspaceImportUnit::isRetryable($unique));
        self::assertTrue(WorkspaceImportUnit::isRetryable(new RuntimeException('Flush failed.', 0, $unique)));
    }

    public function testOtherFailuresAreImportErrors(): void
    {
        self::assertFalse(WorkspaceImportUnit::isRetryable(new RuntimeException('Broken file.')));
        self::assertFalse(WorkspaceImportUnit::isRetryable(new DriverException(self::driver('Data too long', 1406), null)));
    }

    /**
     * @return array{ApiWorkspace, ApiService}
     */
    private function graph(): array
    {
        $workspace = new ApiWorkspace('Insurers', 'insurers');
        $service   = (new ApiService('Mapfre', 'mapfre'))->setWorkspace($workspace);
        $this->manager->persist($workspace);
        $this->manager->persist($service);
        $this->manager->flush();

        return [$workspace, $service];
    }

    private static function driver(string $message, int $code): AbstractException
    {
        return new class($message, 'HY000', $code) extends AbstractException {
        };
    }
}
