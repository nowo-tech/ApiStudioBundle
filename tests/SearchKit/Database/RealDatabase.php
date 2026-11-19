<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Database;

use Doctrine\DBAL\Tools\DsnParser;

use function getenv;
use function is_string;
use function sprintf;

/**
 * The connection of a real-database test from its `AS_*_URL` variable (skipped when unset).
 */
trait RealDatabase
{
    abstract protected static function variable(): string;

    /**
     * @return array<string, mixed>
     */
    protected function connection(): array
    {
        $url = getenv(static::variable());
        if (!is_string($url) || $url === '') {
            self::markTestSkipped(sprintf('%s is not set.', static::variable()));
        }

        return (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($url);
    }
}
