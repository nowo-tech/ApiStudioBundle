<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Enum;

/**
 * Authentication strategies for API services.
 */
enum AuthType: string
{
    case None   = 'none';
    case Basic  = 'basic';
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
