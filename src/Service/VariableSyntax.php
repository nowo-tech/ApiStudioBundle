<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use InvalidArgumentException;

/**
 * Canonical {{variable}} placeholder syntax for API Studio.
 *
 * Variable keys are stored without braces; templates and forms always use {{name}}.
 */
final class VariableSyntax
{
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/';

    private const KEY_PATTERN = '/^[a-zA-Z0-9_.-]+$/';

    public static function normalizeKey(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException('Variable key cannot be empty.');
        }

        if (preg_match(self::PLACEHOLDER_PATTERN, $input, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match(self::KEY_PATTERN, $input) === 1) {
            return $input;
        }

        throw new InvalidArgumentException('Variables must use {{variable_name}} syntax (letters, numbers, dots, dashes, underscores).');
    }

    public static function formatKey(string $key): string
    {
        return '{{' . self::normalizeKey($key) . '}}';
    }

    public static function isPlaceholder(string $input): bool
    {
        return preg_match('/^\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}$/', trim($input)) === 1;
    }
}
