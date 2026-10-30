<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Doctrine\DBAL\Exception as DbalException;
use Exception;
use LogicException;
use Nowo\ApiStudioBundle\Service\SchemaSyncService;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function implode;

final class SchemaSyncServiceTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testCreateMissingSqlSkipsExistingTablesAndIndexes(): void
    {
        $em      = $this->createEntityManager(false);
        $service = new SchemaSyncService($em);

        $all = $service->getCreateSchemaSql();
        self::assertNotEmpty($all);
        self::assertSame($all, $service->getCreateMissingSchemaSql(), 'nothing exists yet');

        foreach ($all as $sql) {
            $em->getConnection()->executeStatement($sql);
        }

        self::assertSame([], $service->getCreateMissingSchemaSql());
        self::assertSame([], $service->getSyncSchemaSql());
    }

    public function testFilterExistingSchemaObjectsHandlesIndexesAndSequences(): void
    {
        $em = $this->createEntityManager(false);
        $em->getConnection()->executeStatement('CREATE TABLE host_t (id INTEGER PRIMARY KEY, name VARCHAR(10))');
        $em->getConnection()->executeStatement('CREATE INDEX idx_host_name ON host_t (name)');
        $service = new SchemaSyncService($em);

        self::assertSame(
            ['CREATE TABLE other (id INT)', 'CREATE UNIQUE INDEX idx_new ON other (id)', 'CREATE SEQUENCE seq_new', 'ALTER TABLE other ADD x INT'],
            $service->filterExistingSchemaObjects([
                'CREATE TABLE host_t (id INT)',
                'CREATE TABLE other (id INT)',
                'CREATE INDEX IDX_HOST_NAME ON host_t (name)',
                'CREATE UNIQUE INDEX idx_new ON other (id)',
                'CREATE SEQUENCE seq_new',
                'ALTER TABLE other ADD x INT',
            ]),
        );
    }

    public function testFilterCreateStatementsForExistingTables(): void
    {
        $service = new SchemaSyncService($this->createEntityManager(false));

        self::assertSame(
            ['CREATE TABLE "fresh" (id INT)', 'CREATE INDEX idx ON known (id)'],
            $service->filterCreateStatementsForExistingTables(
                ['CREATE TABLE public.known (id INT)', 'CREATE TABLE "fresh" (id INT)', 'CREATE INDEX idx ON known (id)'],
                ['known'],
            ),
        );
    }

    /**
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function exceptions(): iterable
    {
        $dbal = static fn (string $message, int $code): Throwable => new class($message, $code) extends Exception implements DbalException {
        };

        yield 'mysql table exists code' => [$dbal('Base table error', 1050), true];
        yield 'dbal unknown code' => [$dbal('Syntax error', 1064), false];
        yield 'already exists message' => [new RuntimeException('relation "x" already exists'), true];
        yield 'duplicate key' => [new RuntimeException('Duplicate key name'), true];
        yield 'duplicate column' => [new RuntimeException('Duplicate column name'), true];
        yield 'duplicate foreign key' => [new RuntimeException('Duplicate foreign key constraint'), true];
        yield 'wrapped' => [new LogicException('outer', 0, $dbal('inner', 1826)), true];
        yield 'unrelated' => [new LogicException('outer', 0, new RuntimeException('disk full')), false];
    }

    #[DataProvider('exceptions')]
    public function testDetectsDuplicateSchemaObjectErrors(Throwable $exception, bool $duplicate): void
    {
        $service = new SchemaSyncService($this->createEntityManager(false));

        self::assertSame($duplicate, $service->isDuplicateSchemaObjectException($exception));
    }

    public function testUpdateSqlOnlyTargetsBundleTables(): void
    {
        $em = $this->createEntityManager();
        $em->getConnection()->executeStatement('DROP TABLE request_history');

        $sql = implode("\n", (new SchemaSyncService($em))->getUpdateSchemaSql());

        self::assertStringContainsString('request_history', $sql);
    }
}
