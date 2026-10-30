<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

/**
 * Exports environment variables to JSON, YAML, or .env format.
 */
final class EnvironmentVariableExporter
{
    public function __construct(
        private readonly DocumentParser $parser,
    ) {
    }

    /** @return array<string, mixed> */
    public function exportEnvironment(ApiEnvironment $environment): array
    {
        $variables = [];
        foreach ($environment->getVariables() as $variable) {
            $variables[] = [
                'key'         => VariableSyntax::formatKey($variable->getVariableKey()),
                'value'       => $variable->getValue(),
                'secret'      => $variable->isSecret(),
                'description' => $variable->getDescription(),
            ];
        }

        return [
            'format'      => 'api-studio-environment',
            'version'     => 1,
            'environment' => [
                'name'       => $environment->getName(),
                'slug'       => $environment->getSlug(),
                'is_default' => $environment->isDefault(),
            ],
            'variables' => $variables,
        ];
    }

    /** @return array<string, mixed> */
    public function exportWorkspace(ApiWorkspace $workspace): array
    {
        $environments = [];
        foreach ($workspace->getEnvironments() as $environment) {
            $environments[] = $this->exportEnvironment($environment);
        }

        return [
            'format'    => 'api-studio-workspace-environments',
            'version'   => 1,
            'workspace' => [
                'name' => $workspace->getName(),
                'slug' => $workspace->getSlug(),
            ],
            'environments' => $environments,
        ];
    }

    public function render(ApiEnvironment $environment, string $format): string
    {
        if ($format === 'env') {
            return $this->toDotEnv([$environment], false);
        }

        return $this->renderData($this->exportEnvironment($environment), $format);
    }

    public function renderWorkspace(ApiWorkspace $workspace, string $format): string
    {
        if ($format === 'env') {
            return $this->toDotEnv($workspace->getEnvironments(), true);
        }

        return $this->renderData($this->exportWorkspace($workspace), $format);
    }

    /** @param array<string, mixed> $data */
    private function renderData(array $data, string $format): string
    {
        return $format === 'yaml' ? $this->parser->encodeYaml($data) : $this->parser->encodeJson($data);
    }

    /**
     * .env lines built from the entities: one block per environment (headed by its name when the whole workspace is
     * exported), each variable as KEY=value (key upper-cased, other characters as underscores) after its description.
     *
     * The file is meant to be loaded by Symfony Dotenv or a POSIX shell, so nothing in it may be evaluated: values are
     * single-quoted (taken literally by both); a value containing a single quote or a line break is double-quoted with
     * every character Dotenv or a shell would interpret escaped (`\\`, `"`, `$`, `\n`, `\r`) — or skipped when it also
     * holds a backtick, which cannot be escaped for both; comment lines
     * (environment names, descriptions) carry no control character, so they cannot inject a line; a key that cannot be
     * a valid environment variable name (`^[A-Z_][A-Z0-9_]*$`) is skipped.
     *
     * @param iterable<ApiEnvironment> $environments
     */
    private function toDotEnv(iterable $environments, bool $workspace): string
    {
        $lines = ['# API Studio environment export'];
        foreach ($environments as $environment) {
            if ($workspace) {
                $lines[] = '';
                $lines[] = '# ' . self::comment($environment->getName());
            } else {
                $lines[] = '# Environment: ' . self::comment($environment->getName());
            }

            foreach ($environment->getVariables() as $variable) {
                $key = self::envKey($variable->getVariableKey());
                if ($key === null) {
                    $lines[] = '# skipped: ' . self::comment($variable->getVariableKey()) . ' is not a valid environment variable name';

                    continue;
                }

                $description = $variable->getDescription();
                if ($description !== null && $description !== '') {
                    $lines[] = '# ' . self::comment($description);
                }
                $value = self::envValue($variable->getValue());
                if ($value === null) {
                    $lines[] = '# skipped: ' . $key . ' cannot be written safely (single quote or line break together with a backtick)';

                    continue;
                }
                $lines[] = $key . '=' . $value;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Upper-cased key, characters other than letters, digits and underscores as underscores; null when the result is
     * not a valid environment variable name.
     */
    private static function envKey(string $key): ?string
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '_', $key));

        return preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1 ? $key : null;
    }

    /**
     * Literal value for Dotenv and POSIX shells (no command substitution, no variable expansion, no extra line); null
     * when the value needs double quotes and holds a backtick (a shell would run it, Dotenv would keep an escape).
     */
    private static function envValue(string $value): ?string
    {
        if (!str_contains($value, "'") && preg_match('/[\r\n]/', $value) !== 1) {
            return "'" . $value . "'";
        }

        if (str_contains($value, '`')) {
            return null;
        }

        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"'  => '\\"',
            '$'  => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
        ]) . '"';
    }

    /**
     * One comment line: control characters (line breaks included) become spaces.
     */
    private static function comment(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }
}
