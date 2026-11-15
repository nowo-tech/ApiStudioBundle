<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use function defined;
use function idn_to_ascii;
use function is_int;
use function is_string;
use function parse_url;
use function preg_match;
use function rtrim;
use function strtolower;

use const IDNA_CHECK_BIDI;
use const IDNA_CHECK_CONTEXTJ;
use const IDNA_DEFAULT;
use const IDNA_NONTRANSITIONAL_TO_ASCII;
use const IDNA_USE_STD3_RULES;
use const INTL_IDNA_VARIANT_UTS46;
use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * The origin of a request URL (1.13, QE-B-M1): `scheme://host[:port]`, lower case, default port omitted; null when the
 * URL has no scheme or host. The host is the one the HTTP client connects to (QE-D-I1): an IDN is converted to
 * punycode as Symfony HttpClient does (null when it cannot be: fail closed), and a trailing dot is dropped.
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

        $host = self::asciiHost($host);
        if ($host === null) {
            return null;
        }

        $scheme = strtolower($scheme);
        $port   = parse_url($url, PHP_URL_PORT);
        $origin = $scheme . '://' . $host;
        if (is_int($port) && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function asciiHost(string $host): ?string
    {
        $host = rtrim($host, '.');
        if ($host === '') {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host) !== 1) {
            return strtolower($host);
        }
        if (!defined('INTL_IDNA_VARIANT_UTS46')) {
            return null;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : null;
    }
}
