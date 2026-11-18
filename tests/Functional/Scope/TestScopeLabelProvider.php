<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Nowo\ApiStudioBundle\Scope\ScopeLabelProviderInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function count;
use function str_replace;

/**
 * "Project <id>" for every scope except `project:raw` (left out: the raw value shows). Counts the calls (no N+1).
 */
final class TestScopeLabelProvider implements ScopeLabelProviderInterface
{
    public int $calls = 0;

    public int $lastBatch = 0;

    public function labels(array $scopes, ApiStudioTenant $tenant): array
    {
        ++$this->calls;
        $this->lastBatch = count($scopes);
        $labels          = [];
        foreach ($scopes as $scope) {
            if ($scope->value !== 'project:raw') {
                $labels[$scope->value] = 'Project ' . str_replace('project:', '', $scope->value);
            }
        }

        return $labels;
    }
}
