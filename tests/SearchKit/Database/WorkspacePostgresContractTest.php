<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Database;

use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tests\SearchKit\Contract\ApiStudioContractCase;

/**
 * SearchKit's provider contract for the workspace group on PostgreSQL 16 (AS_POSTGRES_URL; skipped when unset, the CI job fails on skips).
 */
final class WorkspacePostgresContractTest extends ApiStudioContractCase
{
    use RealDatabase;

    protected static function kind(): SearchKind
    {
        return SearchKind::Workspace;
    }

    protected static function variable(): string
    {
        return 'AS_POSTGRES_URL';
    }
}
