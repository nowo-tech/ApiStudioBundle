<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Enum;

/**
 * HTTP methods for REST endpoints.
 */
enum HttpMethod: string
{
    case Get     = 'GET';
    case Post    = 'POST';
    case Put     = 'PUT';
    case Patch   = 'PATCH';
    case Delete  = 'DELETE';
    case Head    = 'HEAD';
    case Options = 'OPTIONS';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
