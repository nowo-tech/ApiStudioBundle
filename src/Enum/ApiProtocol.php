<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Enum;

/**
 * Supported API protocol types.
 */
enum ApiProtocol: string
{
    case Rest    = 'rest';
    case Soap    = 'soap';
    case Graphql = 'graphql';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
