<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Privacy;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioPersonalData;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\ApiStudioBundle\Tests\Support\PrivacyOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.24 GDPR ports on a real ORM schema (SQLite): erasure deletes only the actor's history rows (bodies included) and
 * anonymises their folders, byte-exact, in keyset batches; the export holds metadata only with secrets masked; the
 * actor column is found when `history.scope` / `folders.enabled` no longer map it; logs carry counts only.
 */
final class DoctrineApiStudioPersonalDataTest extends TestCase
{
    use PrivacyOrmTrait;

    private const ACTOR = 'u:UserA';

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    public function testEraseDeletesOnlyTheActorsHistoryRowsInBatchesAndAnonymisesTheirFolders(): void
    {
        $registry             = $this->privacyRegistry();
        $em                   = $this->privacyEntityManager($registry);
        [$service, $endpoint] = $this->seedEndpoint($em);
        for ($i = 0; $i < 5; ++$i) {
            $this->addHistory($em, $endpoint, self::ACTOR, body: 'body-' . $i);
        }
        $other         = $this->addHistory($em, $endpoint, 'u:UserB');
        $lookAlike     = $this->addHistory($em, $endpoint, 'u:usera');
        $anonymous     = $this->addHistory($em, $endpoint, null);
        $mine          = $this->addFolder($em, $service, self::ACTOR);
        $theirs        = $this->addFolder($em, $service, 'u:UserB', 'Refunds');
        $connection    = $em->getConnection();
        $versionBefore = (int) $connection->fetchOne('SELECT row_version FROM api_studio_folder WHERE id = ?', [$mine]);

        $result = $this->ports($registry, 2)->erase(self::ACTOR);

        self::assertSame(5, $result->historyRowsDeleted);
        self::assertSame(1, $result->foldersAnonymised);
        self::assertSame(0, $this->countRows($connection, 'request_history', "executed_by = 'u:UserA'"));
        self::assertSame(3, $this->countRows($connection, 'request_history'));
        foreach ([$other, $lookAlike, $anonymous] as $kept) {
            self::assertSame(1, $this->countRows($connection, 'request_history', 'id = ' . $kept));
        }
        // The folder stays, without its creator; its row version moved (a stale edit form gets the 409).
        self::assertSame(2, $this->countRows($connection, 'folder'));
        self::assertNull($connection->fetchOne('SELECT created_by FROM api_studio_folder WHERE id = ?', [$mine]));
        self::assertSame($versionBefore + 1, (int) $connection->fetchOne('SELECT row_version FROM api_studio_folder WHERE id = ?', [$mine]));
        self::assertSame('u:UserB', $connection->fetchOne('SELECT created_by FROM api_studio_folder WHERE id = ?', [$theirs]));
        self::assertSame(0, $connection->getTransactionNestingLevel());

        // Counts only in the log: no actor id, no URL.
        self::assertStringNotContainsString('UserA', $this->logger->text());
        self::assertSame(['history_rows' => 5, 'folders_anonymised' => 1], $this->logger->records[0]['context']);

        // Erasing again is harmless.
        $again = $this->ports($registry, 2)->erase(self::ACTOR);
        self::assertSame([0, 0], [$again->historyRowsDeleted, $again->foldersAnonymised]);
    }

    public function testEraseInsideACallersTransactionJoinsIt(): void
    {
        $registry             = $this->privacyRegistry();
        $em                   = $this->privacyEntityManager($registry);
        [$service, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, self::ACTOR);
        $this->addFolder($em, $service, self::ACTOR);
        $connection = $em->getConnection();

        $connection->beginTransaction();
        $result = $this->ports($registry, 1)->erase(self::ACTOR);
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'no commit of the caller\'s transaction');
        $connection->rollBack();

        self::assertSame(1, $result->historyRowsDeleted);
        self::assertSame(1, $this->countRows($connection, 'request_history'), 'the caller rolled the erasure back');
        self::assertSame(self::ACTOR, $connection->fetchOne('SELECT created_by FROM api_studio_folder'));
    }

    public function testExportHoldsMetadataOnlyWithSecretsMasked(): void
    {
        $registry             = $this->privacyRegistry();
        $em                   = $this->privacyEntityManager($registry);
        [$service, $endpoint] = $this->seedEndpoint($em);
        $marker               = 'REDACTED-TEST-pii-marker';
        $id                   = $this->addHistory($em, $endpoint, self::ACTOR, 'https://bob:' . $marker . '@api.example.test/orders?page=2&api_key=' . $marker . '&access_token=' . $marker, body: '{"email":"' . $marker . '"}');
        $this->addHistory($em, $endpoint, 'u:usera', 'https://api.example.test/look-alike');
        $folder = $this->addFolder($em, $service, self::ACTOR);
        $this->addFolder($em, $service, 'u:usera', 'Look-alike');

        $export = $this->ports($registry, 1)->export(self::ACTOR);

        self::assertCount(1, $export->history);
        $row = $export->history[0];
        self::assertSame($id, $row['id']);
        self::assertSame($endpoint->getId(), $row['endpoint_id']);
        self::assertSame('POST', $row['method']);
        self::assertSame(201, $row['status']);
        self::assertSame('https://bob:[REDACTED]@api.example.test/orders?page=2&api_key=[REDACTED]&access_token=[REDACTED]', $row['url']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $row['executed_at']);

        self::assertCount(1, $export->folders);
        self::assertSame($folder, $export->folders[0]['id']);
        self::assertSame('Invoices', $export->folders[0]['name']);
        self::assertSame($service->getId(), $export->folders[0]['service_id']);

        // Never a body, header, error message or secret, wherever it would hide.
        $json = json_encode($export->toArray(), JSON_THROW_ON_ERROR);
        foreach ([$marker, 'trace-header', 'answer-header', 'look-alike', 'Look-alike'] as $absent) {
            self::assertStringNotContainsString($absent, $json);
        }
        self::assertStringNotContainsString($marker, $this->logger->text());
        self::assertSame(['history_rows' => 1, 'folders' => 1], $this->logger->records[0]['context']);
    }

    /**
     * `history.scope: endpoint` (no executed_by mapping) and `folders.enabled: false` (folder class a mapped
     * superclass): rows stored under the earlier configuration are still erased and exported.
     */
    public function testRowsLeftByAnEarlierConfigurationAreStillErasedAndExported(): void
    {
        $before               = $this->privacyRegistry();
        $em                   = $this->privacyEntityManager($before);
        [$service, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, self::ACTOR);
        $this->addFolder($em, $service, self::ACTOR);
        $connection = $em->getConnection();

        $after  = $this->privacyRegistry($connection, false, false, false);
        $ports  = $this->ports($after, 10);
        $export = $ports->export(self::ACTOR);
        self::assertCount(1, $export->history);
        self::assertCount(1, $export->folders);

        $result = $ports->erase(self::ACTOR);
        self::assertSame(1, $result->historyRowsDeleted);
        self::assertSame(1, $result->foldersAnonymised);
        self::assertSame(0, $this->countRows($connection, 'request_history'));
        self::assertNull($connection->fetchOne('SELECT created_by FROM api_studio_folder'));
    }

    /**
     * Neither the actor column nor the folder table exists (history.scope endpoint, folders off, since install).
     */
    public function testNothingToEraseWithoutTheActorColumnOrTheFolderTable(): void
    {
        $registry     = $this->privacyRegistry(null, false, false);
        $em           = $this->privacyEntityManager($registry);
        [, $endpoint] = $this->seedEndpoint($em);
        $this->addHistory($em, $endpoint, self::ACTOR);

        $ports = $this->ports($registry, 10);
        self::assertSame(0, $ports->erase(self::ACTOR)->historyRowsDeleted);
        self::assertSame([], $ports->export(self::ACTOR)->history);
        self::assertSame(1, $this->countRows($em->getConnection(), 'request_history'));
    }

    /**
     * The version-1.23 folder table had no row version column yet: the creator is still cleared.
     */
    public function testAFolderTableWithoutRowVersionIsAnonymisedToo(): void
    {
        $registry   = $this->privacyRegistry(null, true, false);
        $connection = $this->privacyEntityManager($registry)->getConnection();
        $connection->executeStatement('CREATE TABLE api_studio_folder (id INTEGER PRIMARY KEY, name VARCHAR(128), service_id INTEGER, created_by VARCHAR(128), created_at DATETIME)');
        $connection->executeStatement("INSERT INTO api_studio_folder (id, name, service_id, created_by, created_at) VALUES (7, 'Old', 1, 'u:UserA', '2026-01-01 00:00:00')");

        self::assertSame(1, $this->ports($registry, 10)->erase(self::ACTOR)->foldersAnonymised);
        self::assertNull($connection->fetchOne('SELECT created_by FROM api_studio_folder WHERE id = 7'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidActors(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['u:User A'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'non ASCII' => ['u:usér'];
        yield 'control' => ["u:user\n"];
    }

    #[DataProvider('invalidActors')]
    public function testInvalidActorIdsAreRefused(string $actor): void
    {
        $ports = $this->ports($this->privacyRegistry(), 10);

        try {
            $ports->erase($actor);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $ports->export($actor);
    }

    private function ports(ScopeManagerRegistry $registry, int $batchSize): DoctrineApiStudioPersonalData
    {
        return new DoctrineApiStudioPersonalData($this->privacyResolver($registry), new HistorySanitizer(), 'api_studio_', $this->logger, $batchSize);
    }
}
