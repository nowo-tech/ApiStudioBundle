<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function array_filter;
use function array_map;
use function count;

/**
 * What {@see WorkspaceSeeder} creates or updates (1.17): one workspace, matched by (tenant, scope, slug), with its
 * services, endpoints, environments, plain variables and secret references. No secret value can be expressed.
 */
final readonly class WorkspaceSeed
{
    /**
     * @param list<ServiceSeed> $services
     * @param list<EnvironmentSeed> $environments
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ApiStudioTenant $tenant = new ApiStudioTenant('', ''),
        public ?WorkspaceScope $scope = null,
        public ?string $description = null,
        public array $services = [],
        public array $environments = [],
    ) {
        SeedValidation::slug($slug, 'workspace slug');
        SeedValidation::name($name, 'workspace name');
        SeedValidation::unique(array_map(static fn (ServiceSeed $service): string => $service->slug, $services), 'service slug');
        SeedValidation::unique(array_map(static fn (EnvironmentSeed $environment): string => $environment->slug, $environments), 'environment slug');
        if (count(array_filter($environments, static fn (EnvironmentSeed $environment): bool => $environment->isDefault)) > 1) {
            throw new InvalidArgumentException('Api Studio seed: at most one environment can be the default.');
        }
    }

    public function hasSecretReferences(): bool
    {
        foreach ($this->environments as $environment) {
            if ($environment->secretReferences !== []) {
                return true;
            }
        }

        return false;
    }
}
