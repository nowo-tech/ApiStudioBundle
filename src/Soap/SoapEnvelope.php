<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Soap;

use function html_entity_decode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_replace;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * Helpers for the raw-envelope SOAP mode: the endpoint body is a complete SOAP envelope that is POSTed as-is
 * (after variable substitution) instead of being built by ext-soap from JSON parameters.
 *
 * Pure string helpers: they need neither ext-soap nor ext-dom, so raw-envelope calls work without ext-soap.
 *
 * @internal
 */
final class SoapEnvelope
{
    /** Same value as ext-soap's SOAP_1_1 (usable without the extension). */
    public const VERSION_1_1 = 1;

    /** Same value as ext-soap's SOAP_1_2 (usable without the extension). */
    public const VERSION_1_2 = 2;

    /** Header names owned by the SOAP transport (lower-case). */
    private const FRAMING_HEADERS = ['content-type', 'soapaction'];

    private const SOAP_12_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';

    /** Element name with an optional namespace prefix, e.g. `soap:` or `SOAP-ENV:`. */
    private const PREFIX = '(?:[A-Za-z_][\w.-]*:)?';

    /**
     * True when $body is an XML document whose content is a SOAP `Envelope` element (any prefix).
     */
    public static function isEnvelope(string $body): bool
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($body, '<')) {
            return false;
        }

        return preg_match('/<' . self::PREFIX . 'Envelope[\s>\/]/', $body) === 1;
    }

    /**
     * SOAP 1.2 when the envelope uses the SOAP 1.2 namespace, SOAP 1.1 otherwise.
     */
    public static function version(string $envelope): int
    {
        return str_contains($envelope, self::SOAP_12_NAMESPACE) ? self::VERSION_1_2 : self::VERSION_1_1;
    }

    /**
     * HTTP headers that frame a SOAP request: `text/xml` + `SOAPAction: "<action>"` (SOAP 1.1) or
     * `application/soap+xml; action="<action>"` (SOAP 1.2).
     *
     * @return array<string, string>
     */
    public static function framingHeaders(string $action, int $version): array
    {
        return $version === self::VERSION_1_2
            ? ['Content-Type' => 'application/soap+xml; charset=utf-8; action="' . $action . '"']
            : ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"' . $action . '"'];
    }

    /**
     * Removes the SOAP framing headers (`Content-Type`, `SOAPAction`, case-insensitive) and empty header names.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function withoutFramingHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || in_array(strtolower($name), self::FRAMING_HEADERS, true)) {
                continue;
            }
            $filtered[$name] = $value;
        }

        return $filtered;
    }

    /**
     * URL the envelope is POSTed to: the service URL without a WSDL marker query (`?wsdl`, `?WSDL`,
     * `?singleWsdl`, `?wsdl=…`) and without fragment. Any other query string is kept.
     */
    public static function endpointUrl(string $serviceUrl): string
    {
        $url = (string) preg_replace('/#.*$/s', '', $serviceUrl);

        return (string) preg_replace('/\?(?:wsdl|singlewsdl)(?:=[^&]*)?$/i', '', $url);
    }

    /**
     * Operation name for ext-soap (`__soapCall`) from the endpoint SOAP action: a bare operation name is returned
     * as-is; for a SOAPAction URI (e.g. `http://tempuri.org/Svc/GetThing` or `urn:svc#GetThing`) the last
     * segment after `/`, `#` or `:` is used.
     */
    public static function operationName(string $action): string
    {
        $action = trim($action, " \t\"");
        $cut    = -1;
        foreach (['/', '#', ':'] as $separator) {
            $position = strrpos($action, $separator);
            if ($position !== false && $position > $cut) {
                $cut = $position;
            }
        }

        $name = $cut >= 0 ? substr($action, $cut + 1) : $action;

        return $name !== '' ? $name : $action;
    }

    /**
     * Human-readable SOAP Fault from a response envelope (`faultcode`/`faultstring` for SOAP 1.1,
     * `Code/Value` + `Reason/Text` for SOAP 1.2), or null when the body carries no Fault.
     */
    public static function fault(string $responseBody): ?string
    {
        if (preg_match('/<' . self::PREFIX . 'Fault[\s>\/](.*?)<\/' . self::PREFIX . 'Fault\s*>/s', $responseBody, $match) !== 1) {
            return preg_match('/<' . self::PREFIX . 'Fault\s*\/>/', $responseBody) === 1 ? 'SOAP Fault' : null;
        }

        $fault  = $match[1];
        $code   = self::text($fault, 'faultcode') ?? self::text($fault, 'Value');
        $reason = self::text($fault, 'faultstring') ?? self::text($fault, 'Text');

        $message = 'SOAP Fault';
        if ($code !== null) {
            $message .= ' (' . $code . ')';
        }

        return $reason !== null ? $message . ': ' . $reason : $message;
    }

    private static function text(string $xml, string $element): ?string
    {
        if (preg_match('/<' . self::PREFIX . $element . '(?:\s[^>]*)?>(.*?)<\/' . self::PREFIX . $element . '\s*>/s', $xml, $match) !== 1) {
            return null;
        }

        $text = trim(html_entity_decode((string) preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $text !== '' ? $text : null;
    }
}
