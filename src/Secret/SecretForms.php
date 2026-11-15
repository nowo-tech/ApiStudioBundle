<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use function array_unique;
use function array_values;
use function base64_encode;
use function htmlspecialchars;
use function is_string;
use function json_encode;
use function rtrim;
use function strlen;
use function strtr;
use function substr;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;

/**
 * Encodings of a resolved host secret a target may echo back (1.13, QE-B-L1), masked next to the raw, URL-encoded and
 * JSON-escaped forms: base64 (standard and URL-safe, also of `user:secret` for Basic auth), HTML entities and `\u`
 * JSON escapes. Other encodings (hashes, truncation, compression) stay a documented residual risk.
 *
 * @internal
 */
final class SecretForms
{
    /**
     * @param list<string> $values resolved host secrets
     * @param string|null $basicUser the Basic auth user name of the request, when it uses Basic auth
     *
     * @return list<string> the values and their encoded forms
     */
    public static function expand(array $values, ?string $basicUser = null): array
    {
        $forms = [];
        foreach ($values as $value) {
            $forms[] = $value;
            $encoded = [base64_encode($value)];
            if ($basicUser !== null) {
                $encoded[] = base64_encode($basicUser . ':' . $value);
            }
            foreach ($encoded as $base64) {
                $forms[] = $base64;
                $forms[] = rtrim(strtr($base64, '+/', '-_'), '=');
            }
            $forms[] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
            $forms[] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
            foreach ([0, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT] as $flags) {
                $json = json_encode($value, $flags);
                if (is_string($json) && strlen($json) > 2) {
                    $forms[] = substr($json, 1, -1);
                }
            }
        }

        return array_values(array_unique($forms));
    }
}
