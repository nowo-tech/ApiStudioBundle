<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;

use function array_map;
use function strlen;

/**
 * A service of a {@see WorkspaceSeed} (1.17), matched by slug within the workspace. Auth settings and default headers
 * are not seeded (they may hold credentials); use environment variables and secret references instead.
 */
final readonly class ServiceSeed
{
    /**
     * @param list<EndpointSeed> $endpoints
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $baseUrl = '{{base_url}}',
        public ApiProtocol $protocol = ApiProtocol::Rest,
        public ?string $description = null,
        public array $endpoints = [],
    ) {
        SeedValidation::slug($slug, 'service slug');
        SeedValidation::name($name, 'service name');
        if ($baseUrl === '' || strlen($baseUrl) > 512) {
            throw new InvalidArgumentException('Api Studio seed: service base URL must be 1 to 512 characters.');
        }
        SeedValidation::unique(array_map(static fn (EndpointSeed $endpoint): string => $endpoint->slug, $endpoints), 'endpoint slug');
    }
}
