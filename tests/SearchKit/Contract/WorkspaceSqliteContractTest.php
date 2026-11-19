<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Contract;

use Nowo\ApiStudioBundle\Search\SearchKind;

use function extension_loaded;

/**
 * SearchKit's provider contract for the workspace group on SQLite.
 */
final class WorkspaceSqliteContractTest extends ApiStudioContractCase
{
    protected static function kind(): SearchKind
    {
        return SearchKind::Workspace;
    }

    protected function connection(): array
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        return ['driver' => 'pdo_sqlite', 'memory' => true];
    }
}
