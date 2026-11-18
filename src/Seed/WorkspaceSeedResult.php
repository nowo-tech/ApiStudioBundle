<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

/**
 * Outcome of {@see WorkspaceSeeder::seed()} (1.17): ids and counts only, never a value.
 */
final readonly class WorkspaceSeedResult
{
    /**
     * @param array<string, int> $created rows inserted per kind (service, endpoint, environment, variable)
     * @param array<string, int> $updated rows updated per kind
     */
    public function __construct(
        public int $workspaceId,
        public bool $workspaceCreated,
        public int $attempts,
        public array $created = [],
        public array $updated = [],
    ) {
    }
}
