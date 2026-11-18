<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use InvalidArgumentException;
use Stringable;

use function preg_match;
use function sprintf;

/**
 * Opaque host sub-scope of a workspace (1.17, `nowo_api_studio.scope`), e.g. `project:0b9c…`: one level below the
 * tenant. The bundle never parses it; it only compares it **byte-exact** (`===`, binary column), so `Project:1` never
 * matches `project:1` (REQ-SEC-006, LQ rule). No trimming, no case folding: an invalid value is refused, not fixed.
 */
final readonly class WorkspaceScope implements Stringable
{
    public const PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    /** Column length of `host_scope`. */
    public const MAX_LENGTH = 128;

    public function __construct(
        public string $value,
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Api Studio workspace scope: it must match %s.', self::PATTERN));
        }
    }

    /**
     * The scope of a stored value: null for `''` (tenant level).
     */
    public static function fromStored(string $value): ?self
    {
        return $value === '' ? null : new self($value);
    }

    /**
     * Stored form of an optional scope: `''` = tenant level.
     */
    public static function key(?self $scope): string
    {
        return $scope instanceof self ? $scope->value : '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
