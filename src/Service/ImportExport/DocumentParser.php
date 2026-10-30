<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

use function is_array;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_EXTENSION;

/**
 * Parses JSON or YAML documents for import.
 *
 * A parsed document may hold at most {@see self::MAX_NODES} values: YAML aliases are expanded by reference, so a few
 * hundred bytes can describe millions of nodes ("billion laughs") that the importers would then walk and persist.
 */
final class DocumentParser
{
    /** Largest number of values (scalars and collections) an imported document may contain. */
    public const MAX_NODES = 100_000;

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
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON or YAML document.');
        }

        self::assertNodeBudget($data);

        return $data;
    }

    /**
     * Counts the values of the document (stops at the budget, so an expanded alias bomb is never walked in full).
     *
     * @param array<mixed> $data
     */
    private static function assertNodeBudget(array $data): void
    {
        $count = 0;
        $stack = [$data];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node as $value) {
                if (++$count > self::MAX_NODES) {
                    throw new InvalidArgumentException(sprintf('The document has more than %d values (YAML aliases expand into copies); split it or remove the aliases.', self::MAX_NODES));
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
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
