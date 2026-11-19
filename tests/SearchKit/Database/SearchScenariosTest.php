<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Database;

use Doctrine\DBAL\Tools\DsnParser;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\SearchScenarios;
use PHPUnit\Framework\Attributes\DataProvider;
use Nowo\ApiStudioBundle\Tests\SearchKit\SkipsWithoutSearchKit;
use PHPUnit\Framework\TestCase;

use function getenv;
use function is_string;
use function sprintf;

/**
 * 1.18 SearchKit scenarios on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): org and project visibility,
 * look-alike tenants and scopes against the servers' own collations, an access-check deny and a host deny, folders,
 * LIKE escaping, no secret in results, the query budget. Runs only when AS_MYSQL_URL, AS_MARIADB_URL and
 * AS_POSTGRES_URL are set (CI job "Databases"); the job fails on any skipped test.
 */
final class SearchScenariosTest extends TestCase
{
    use SearchScenarios;
    use SkipsWithoutSearchKit;

    private ?ScopeManagerRegistry $registry = null;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        $scenarios = [
            'org and project visibility' => 'assertOrgAndProjectVisibility',
            'tenants never leak'         => 'assertTenantsNeverLeak',
            'access deny'                => 'assertAccessDenyHidesRows',
            'folders'                    => 'assertFoldersNarrowEndpoints',
            'LIKE escaping'              => 'assertLikeIsEscaped',
            'endpoints and secrets'      => 'assertEndpointsByNamePathAndMethod',
            'query budget'               => 'assertQueryBudget',
        ];
        foreach (['MySQL 8.4' => 'AS_MYSQL_URL', 'MariaDB 11.4' => 'AS_MARIADB_URL', 'PostgreSQL 16' => 'AS_POSTGRES_URL'] as $server => $variable) {
            foreach ($scenarios as $label => $method) {
                yield $server . ': ' . $label => [$variable, $method];
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->registry instanceof ScopeManagerRegistry) {
            $this->dropSearchSchema($this->registry);
        }
        $this->registry = null;
    }

    #[DataProvider('cases')]
    public function testScenario(string $variable, string $scenario): void
    {
        $this->skipWithoutSearchKit();
        $url = getenv($variable);
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', $variable));
        }

        $this->registry = $this->searchRegistry((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($url));
        $this->seedSearchData($this->registry);
        $this->{$scenario}($this->registry);
    }
}
