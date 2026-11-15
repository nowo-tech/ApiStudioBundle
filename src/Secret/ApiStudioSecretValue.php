<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * A resolved host secret (1.13, {@see ApiStudioSecretSourceInterface}). The value is only readable through
 * {@see self::reveal()}: it is kept outside the object's properties (QE-B-L3), so VarDumper, `var_export`, an
 * `(array)` cast, `print_r` and `json_encode` never show it; the object cannot be cloned or serialised.
 */
final class ApiStudioSecretValue
{
    /**
     * Values by object, kept out of the property table (QE-B-L3). Weak keys: an entry lives exactly as long as its
     * value object (one execution), so nothing persists across worker requests (tested).
     *
     * @var WeakMap<self, string>|null
     */
    // @igor-ignore - weak per-object storage, freed with each value object
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak keys: entries die with their per-execution object)
    private static ?WeakMap $values = null;

    public function __construct(
        #[SensitiveParameter]
        string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('An Api Studio secret value cannot be empty: return null instead.');
        }

        // @igor-ignore - weak per-object storage, freed with each value object
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak keys)
        // @igor-ignore - weak per-object storage, freed with each value object
        self::$values[$this] = $value; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak keys)
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? throw new LogicException('Api Studio secret value not available.');
    }

    public function __clone()
    {
        throw new LogicException('An Api Studio secret value cannot be cloned.');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An Api Studio secret value cannot be unserialised.');
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
