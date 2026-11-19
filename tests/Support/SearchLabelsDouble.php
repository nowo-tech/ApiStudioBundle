<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Scope\ScopeLabelProviderInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Scope labels double: `Project <value>` for every scope; counts the calls.
 */
final class SearchLabelsDouble implements ScopeLabelProviderInterface
{
    public int $calls = 0;

    public function labels(array $scopes, ApiStudioTenant $tenant): array
    {
        ++$this->calls;
        $labels = [];
        foreach ($scopes as $scope) {
            $labels[$scope->value] = 'Project ' . $scope->value;
        }

        return $labels;
    }
}
