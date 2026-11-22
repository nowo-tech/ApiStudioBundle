<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\WorkspaceQueryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function getenv;
use function is_string;
use function sprintf;

/**
 * 1.19 (DDD-host checklist B7, house LQ rule) on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the workspace
 * query port compares tenants and scopes exactly — a case-folded or padded look-alike stored next to the real key never
 * matches — and its text filter is literal. Runs only when AS_MYSQL_URL, AS_MARIADB_URL and AS_POSTGRES_URL are set (CI
 * job "Databases", `make test-with-db`).
 */
final class WorkspaceQueryExactKeysTest extends TestCase
{
    use ScopeOrmTrait;
    use WorkspaceQueryScenario;

    private ?Connection $connection = null;

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
        $this->connection?->close();
        $this->connection = null;
    }

    #[DataProvider('servers')]
    public function testExactKeysOnTheServer(string $variable): void
    {
        $url = getenv($variable);
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', $variable));
        }

        $parser           = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url), $this->scopeConfiguration());
        $registry         = new ScopeManagerRegistry($this->connection, fn (Connection $connection): EntityManagerInterface => $this->scopeManager($connection));
        $em               = $this->scopeEntityManager($registry);
        $this->createScopeSchema($em);

        $this->assertWorkspaceQueryScenario($em, $registry, $this->seedWorkspaceQueryScenario($em));
    }
}
