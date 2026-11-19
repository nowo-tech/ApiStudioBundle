<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Database;

use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tests\SearchKit\Contract\ApiStudioContractCase;

/**
 * SearchKit's provider contract for the endpoint group on MariaDB 11.4 (AS_MARIADB_URL; skipped when unset, the CI job fails on skips).
 */
final class EndpointMariadbContractTest extends ApiStudioContractCase
{
    use RealDatabase;

    protected static function kind(): SearchKind
    {
        return SearchKind::Endpoint;
    }

    protected static function variable(): string
    {
        return 'AS_MARIADB_URL';
    }
}
