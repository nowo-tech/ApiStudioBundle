<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Script;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ApiStudioBundle\Entity\ApiScriptVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Script\ScriptVariableStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function end;
use function in_array;

/**
 * Audit RW-L3: both scopes of a save are written in one transaction, after the workspace row is locked, so the
 * per-scope cap is checked under the lock (no race between two saves) and a failure leaves nothing behind.
 */
final class ScriptVariableStoreTest extends TestCase
{
    public function testSaveLocksTheWorkspaceThenChecksAndWritesBothScopesInOneTransaction(): void
    {
        $log        = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'BEGIN';
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'COMMIT';
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'ROLLBACK';
        });
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$log): int {
            $log[] = $sql;
            if (in_array('fail_key', $params, true)) {
                throw new RuntimeException('write failed');
            }

            return 1;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use (&$log): array {
            $log[] = 'COUNT';

            return [];
        });

        $store = new ScriptVariableStore($this->entityManager($connection));
        self::assertSame(2, $store->save(7, 9, ['g' => '1'], ['c' => '2']));
        self::assertSame('BEGIN', $log[0]);
        self::assertSame('UPDATE api_studio_workspace SET id = id WHERE id = ?', $log[1], 'the workspace row is locked before the cap is read');
        self::assertSame(['COUNT', 'COUNT'], [$log[2], $log[3]]);
        self::assertSame('COMMIT', end($log));

        $log = [];
        try {
            $store->save(7, 9, ['g' => '1'], ['c' => null, 'fail_key' => null]);
            self::fail('expected the write to fail');
        } catch (RuntimeException) {
        }
        /* @var list<string> $log */
        self::assertContains('ROLLBACK', $log, 'a failure in the second scope rolls back the first');
        self::assertNotContains('COMMIT', $log);
    }

    private function entityManager(Connection $connection): EntityManagerInterface
    {
        $variables = $this->createMock(ClassMetadata::class);
        $variables->method('getTableName')->willReturn('api_studio_script_variable');
        $workspaces = $this->createMock(ClassMetadata::class);
        $workspaces->method('getTableName')->willReturn('api_studio_workspace');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnMap([[ApiScriptVariable::class, $variables], [ApiWorkspace::class, $workspaces]]);

        return $entityManager;
    }
}
