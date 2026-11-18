<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

/**
 * Counters of one seed attempt (created / updated rows per kind). Built per attempt, never shared.
 *
 * @internal
 */
final class SeedStats
{
    public bool $workspaceCreated = false;

    /** @var array<string, int> */
    public array $created = [];

    /** @var array<string, int> */
    public array $updated = [];

    public function count(string $kind, bool $existed): void
    {
        if ($existed) {
            // @igor-ignore - Per-attempt value object built by the seeder, never a shared service.
            $this->updated[$kind] = ($this->updated[$kind] ?? 0) + 1;

            return;
        }

        // @igor-ignore - Per-attempt value object built by the seeder, never a shared service.
        $this->created[$kind] = ($this->created[$kind] ?? 0) + 1;
    }
}
