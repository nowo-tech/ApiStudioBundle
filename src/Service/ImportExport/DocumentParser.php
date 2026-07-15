<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

use function is_array;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_EXTENSION;

/**
 * Parses JSON or YAML documents for import.
 */
final class DocumentParser
{
    /** @return array<string, mixed> */
    public function parse(string $content, ?string $filename = null): array
    {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Empty document.');
        }

        $extension = $filename !== null ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';
        if ($extension === 'yaml' || $extension === 'yml' || str_starts_with($trimmed, 'openapi:') || str_starts_with($trimmed, 'swagger:')) {
            $data = Yaml::parse($content);
        } else {
            $data = json_decode($content, true);
            if (!is_array($data) && ($extension === 'yaml' || $extension === 'yml')) {
                $data = Yaml::parse($content);
            }
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON or YAML document.');
        }

        return $data;
    }

    public function encodeJson(mixed $data, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($data, $flags) ?: '{}';
    }

    public function encodeYaml(mixed $data): string
    {
        return Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
