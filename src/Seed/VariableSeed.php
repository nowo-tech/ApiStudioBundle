<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;

/**
 * A plain (non-secret) environment variable of an {@see EnvironmentSeed} (1.17), matched by key. Never a secret: secret
 * values cannot be seeded at all, and a host secret reference goes through {@see SecretReferenceSeed}.
 */
final readonly class VariableSeed
{
    public function __construct(
        public string $key,
        public string $value,
        public ?string $description = null,
    ) {
        SeedValidation::key($key, 'variable key');
        if (ApiStudioSecretRef::parse($value) !== null) {
            throw new InvalidArgumentException('Api Studio seed: a host secret reference must be seeded as a SecretReferenceSeed, not as a plain variable.');
        }
    }
}
