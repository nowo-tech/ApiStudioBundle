<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Seed;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Validator\SlugFormat;

use function mb_strlen;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;

/**
 * Shared checks of the seed value objects (1.17). Messages name the field, never a value (a seed must never leak what
 * it carries into logs).
 *
 * @internal
 */
final class SeedValidation
{
    public const KEY_PATTERN = '/^[a-zA-Z0-9_.-]{1,128}$/D';

    public static function slug(string $slug, string $field): string
    {
        if (strlen($slug) > SlugFormat::MAX_LENGTH || preg_match(SlugFormat::PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(sprintf('Api Studio seed: %s must be a slug (%s, at most %d characters).', $field, SlugFormat::PATTERN, SlugFormat::MAX_LENGTH));
        }

        return $slug;
    }

    public static function name(string $name, string $field, int $max = 128): string
    {
        if (trim($name) === '' || mb_strlen($name) > $max) {
            throw new InvalidArgumentException(sprintf('Api Studio seed: %s must be 1 to %d characters.', $field, $max));
        }

        return $name;
    }

    public static function key(string $key, string $field): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Api Studio seed: %s must match %s.', $field, self::KEY_PATTERN));
        }

        return $key;
    }

    /**
     * @param list<string> $keys
     */
    public static function unique(array $keys, string $field): void
    {
        $seen = [];
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('Api Studio seed: duplicate %s "%s".', $field, $key));
            }
            $seen[$key] = true;
        }
    }
}
