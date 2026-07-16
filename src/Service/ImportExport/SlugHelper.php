<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use function in_array;

/**
 * Generates URL-safe slugs for imported entities.
 */
final class SlugHelper
{
    public static function fromString(string $value, string $fallback = 'item'): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? substr($slug, 0, 128) : $fallback;
    }

    /** @param array<int, string> $existing */
    public static function unique(string $base, array $existing): string
    {
        $slug = self::fromString($base);
        if (!in_array($slug, $existing, true)) {
            return $slug;
        }

        $i = 2;
        while (in_array($slug . '_' . $i, $existing, true)) {
            ++$i;
        }

        return substr($slug . '_' . $i, 0, 128);
    }
}
