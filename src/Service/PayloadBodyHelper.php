<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use DOMDocument;
use InvalidArgumentException;
use JsonException;

use function count;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LIBXML_NOERROR;
use const LIBXML_NONET;

/**
 * Formats and validates REST (JSON) and SOAP (XML) request/response bodies.
 * Supports {{variable}} placeholders during validation and formatting.
 */
final class PayloadBodyHelper
{
    private const VAR_PATTERN = VariableSyntax::PLACEHOLDER_PATTERN;

    public function resolveBodyFormat(string $protocol, ?string $contentType = null): string
    {
        if ($protocol === 'soap') {
            return 'xml';
        }

        $contentType = strtolower($contentType ?? '');
        if (str_contains($contentType, 'xml') || str_contains($contentType, 'soap')) {
            return 'xml';
        }

        return 'json';
    }

    /**
     * @return array{valid: bool, with_variables: bool, message: string}
     */
    public function validate(string $body, string $format): array
    {
        return $format === 'xml' ? $this->validateXml($body) : $this->validateJson($body);
    }

    public function format(string $body, string $format): string
    {
        return $format === 'xml' ? $this->formatXml($body) : $this->formatJson($body);
    }

    /**
     * @return array{valid: bool, with_variables: bool, message: string}
     */
    public function validateJson(string $body): array
    {
        if (trim($body) === '') {
            return ['valid' => true, 'with_variables' => false, 'message' => 'empty'];
        }

        $hasVariables = $this->containsVariablePlaceholders($body);

        try {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return [
                'valid'          => true,
                'with_variables' => $hasVariables,
                'message'        => $hasVariables ? 'valid_json_with_variables' : 'valid_json',
            ];
        } catch (JsonException) {
            try {
                json_decode($this->maskVariablesForJson($body), true, 512, JSON_THROW_ON_ERROR);

                return ['valid' => true, 'with_variables' => true, 'message' => 'valid_json_with_variables'];
            } catch (JsonException $e) {
                return ['valid' => false, 'with_variables' => false, 'message' => $e->getMessage()];
            }
        }
    }

    /**
     * @return array{valid: bool, with_variables: bool, message: string}
     */
    public function validateXml(string $body): array
    {
        if (trim($body) === '') {
            return ['valid' => true, 'with_variables' => false, 'message' => 'empty'];
        }

        $hasVariables = $this->containsVariablePlaceholders($body);

        if ($this->loadXml($body) !== null) {
            return [
                'valid'          => true,
                'with_variables' => $hasVariables,
                'message'        => $hasVariables ? 'valid_xml_with_variables' : 'valid_xml',
            ];
        }

        [$masked] = $this->maskVariablesForXml($body);
        if ($this->loadXml($masked) !== null) {
            return ['valid' => true, 'with_variables' => true, 'message' => 'valid_xml_with_variables'];
        }

        return ['valid' => false, 'with_variables' => false, 'message' => $this->lastXmlError() ?? 'Invalid XML.'];
    }

    public function formatJson(string $body): string
    {
        if (trim($body) === '') {
            return $body;
        }

        $masked    = $this->maskVariablesForJson($body);
        $decoded   = json_decode($masked, true, 512, JSON_THROW_ON_ERROR);
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $formatted === false ? $body : $formatted . "\n";
    }

    public function formatXml(string $body): string
    {
        if (trim($body) === '') {
            return $body;
        }

        [$masked, $tokens] = $this->maskVariablesForXml($body);
        $dom               = $this->loadXml($masked);
        if ($dom === null) {
            throw new InvalidArgumentException($this->lastXmlError() ?? 'Invalid XML.');
        }

        $dom->formatOutput       = true;
        $dom->preserveWhiteSpace = false;
        $formatted               = $dom->saveXML();
        if ($formatted === false) {
            throw new InvalidArgumentException('Unable to format XML.');
        }

        foreach ($tokens as $index => $original) {
            $formatted = str_replace('___ASVAR' . $index . '___', $original, $formatted);
        }

        return $formatted;
    }

    private function maskVariablesForJson(string $body): string
    {
        return (string) preg_replace_callback(
            self::VAR_PATTERN,
            static fn (array $matches): string => json_encode($matches[0], JSON_THROW_ON_ERROR),
            $body,
        );
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function maskVariablesForXml(string $body): array
    {
        $tokens = [];

        $masked = (string) preg_replace_callback(
            self::VAR_PATTERN,
            static function (array $matches) use (&$tokens): string {
                $token    = '___ASVAR' . count($tokens) . '___';
                $tokens[] = $matches[0];

                return $token;
            },
            $body,
        );

        return [$masked, $tokens];
    }

    private function loadXml(string $body): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom                     = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;

        if (@$dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR)) {
            libxml_use_internal_errors($previous);

            return $dom;
        }

        libxml_use_internal_errors($previous);

        return null;
    }

    private function lastXmlError(): ?string
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($errors === []) {
            return null;
        }

        return trim($errors[0]->message);
    }

    private function containsVariablePlaceholders(string $body): bool
    {
        return preg_match(self::VAR_PATTERN, $body) === 1;
    }
}
