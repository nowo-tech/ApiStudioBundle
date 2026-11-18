<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Enum\HttpMethod;

use function strlen;

/**
 * An endpoint of a {@see ServiceSeed} (1.17), matched by slug within its service. No body template, headers or
 * scripts: a seed carries structure, not request content that could hold a secret.
 */
final readonly class EndpointSeed
{
    public function __construct(
        public string $slug,
        public string $name,
        public HttpMethod $method = HttpMethod::Get,
        public string $path = '/',
        public string $contentType = 'application/json',
    ) {
        SeedValidation::slug($slug, 'endpoint slug');
        SeedValidation::name($name, 'endpoint name');
        if ($path === '' || strlen($path) > 512) {
            throw new InvalidArgumentException('Api Studio seed: endpoint path must be 1 to 512 characters.');
        }
        SeedValidation::name($contentType, 'endpoint content type');
    }
}
