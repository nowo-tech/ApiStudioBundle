<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * A resolved host secret (1.13, {@see ApiStudioSecretSourceInterface}). The value is only readable through
 * {@see self::reveal()}: dumps show a mask and the object cannot be serialised.
 */
final readonly class ApiStudioSecretValue
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('An Api Studio secret value cannot be empty: return null instead.');
        }
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('An Api Studio secret value cannot be serialised.');
    }
}
