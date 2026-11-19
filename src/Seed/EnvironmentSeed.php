<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use function array_map;

/**
 * An environment of a {@see WorkspaceSeed} (1.17), matched by slug within the workspace. Variables are matched by key;
 * a key is either a plain variable or a secret reference, never both.
 */
final readonly class EnvironmentSeed
{
    /**
     * @param list<VariableSeed> $variables
     * @param list<SecretReferenceSeed> $secretReferences
     */
    public function __construct(
        public string $slug,
        public string $name,
        public bool $isDefault = false,
        public array $variables = [],
        public array $secretReferences = [],
    ) {
        SeedValidation::slug($slug, 'environment slug');
        SeedValidation::name($name, 'environment name');
        SeedValidation::unique([
            ...array_map(static fn (VariableSeed $variable): string => $variable->key, $variables),
            ...array_map(static fn (SecretReferenceSeed $reference): string => $reference->key, $secretReferences),
        ], 'variable key');
    }
}
