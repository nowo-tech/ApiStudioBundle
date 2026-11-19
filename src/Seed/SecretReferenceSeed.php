<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface;

use function preg_match;

/**
 * A secret environment variable of an {@see EnvironmentSeed} (1.17) that holds a host secret **reference** by name,
 * never a value: stored as `{{secret:<source>/<id>}}` and resolved on the server at execute time by the host's
 * {@see ApiStudioSecretSourceInterface} (1.13, `secrets.source`).
 */
final readonly class SecretReferenceSeed
{
    public function __construct(
        public string $key,
        public string $source,
        public string $id,
        public ?string $description = null,
    ) {
        SeedValidation::key($key, 'secret reference key');
        if (preg_match(ApiStudioSecretRef::SOURCE_PATTERN, $source) !== 1 || preg_match(ApiStudioSecretRef::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Api Studio seed: a secret reference needs a source matching ' . ApiStudioSecretRef::SOURCE_PATTERN . ' and an id matching ' . ApiStudioSecretRef::ID_PATTERN . '.');
        }
    }

    /**
     * The stored value: the reference itself.
     */
    public function reference(): string
    {
        return '{{secret:' . $this->source . '/' . $this->id . '}}';
    }
}
