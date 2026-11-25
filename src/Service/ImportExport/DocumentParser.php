<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
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
 *
 * Every rejection is an {@see InvalidArgumentException} whose message never quotes the document: recent symfony/yaml
 * releases stop an alias bomb themselves with a {@see ParseException} (whose message cites the offending line), so
 * parse errors are translated here and the alias limit gets the same message as the node budget below.
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
            $data = self::parseYaml($content);
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
     * Parses YAML, turning any {@see ParseException} into a clean rejection (no excerpt of the payload in the message).
     */
    private static function parseYaml(string $content): mixed
    {
        try {
            return Yaml::parse($content);
        } catch (ParseException $e) {
            // symfony/yaml >= 7.4.24 / 8.1.12 caps alias expansion itself ("Maximum number of nodes expanded by ...
            // aliases"): report it like our own budget so the user sees one consistent reason.
            if (str_contains($e->getMessage(), 'aliases') && str_contains($e->getMessage(), 'exceeded')) {
                throw self::budgetExceeded();
            }

            $line = $e->getParsedLine();

            throw new InvalidArgumentException($line > 0 ? sprintf('Invalid YAML document (line %d).', $line) : 'Invalid YAML document.');
        }
    }

    private static function budgetExceeded(): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The document has more than %d values (YAML aliases expand into copies); split it or remove the aliases.', self::MAX_NODES));
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
                    throw self::budgetExceeded();
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
