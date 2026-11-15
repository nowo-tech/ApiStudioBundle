<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use function is_int;
use function is_string;
use function parse_url;
use function strtolower;

use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * The origin of a request URL (1.13, QE-B-M1): `scheme://host[:port]`, lower case, default port omitted; null when the
 * URL has no scheme or host.
 *
 * @internal
 */
final class RequestOrigin
{
    public static function of(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || $scheme === '' || !is_string($host) || $host === '') {
            return null;
        }

        $scheme = strtolower($scheme);
        $port   = parse_url($url, PHP_URL_PORT);
        $origin = $scheme . '://' . strtolower($host);
        if (is_int($port) && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':' . $port;
        }

        return $origin;
    }
}
