<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

use function is_array;

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
        return $this->renderData($this->exportEnvironment($environment), $format);
    }

    public function renderWorkspace(ApiWorkspace $workspace, string $format): string
    {
        return $this->renderData($this->exportWorkspace($workspace), $format);
    }

    /** @param array<string, mixed> $data */
    private function renderData(array $data, string $format): string
    {
        return match ($format) {
            'yaml'  => $this->parser->encodeYaml($data),
            'env'   => $this->toDotEnv($data),
            default => $this->parser->encodeJson($data),
        };
    }

    /** @param array<string, mixed> $data */
    private function toDotEnv(array $data): string
    {
        $lines = ['# API Studio environment export'];
        if (isset($data['environment']) && is_array($data['environment'])) {
            $lines[] = '# Environment: ' . ($data['environment']['name'] ?? 'unknown');
        }

        $variables = $data['variables'] ?? [];
        if (!is_array($variables) && isset($data['environments']) && is_array($data['environments'])) {
            foreach ($data['environments'] as $environment) {
                if (!is_array($environment)) {
                    continue;
                }
                $lines[] = '';
                $lines[] = '# ' . ($environment['environment']['name'] ?? 'environment');
                foreach ($environment['variables'] ?? [] as $variable) {
                    if (!is_array($variable)) {
                        continue;
                    }
                    $lines = array_merge($lines, $this->dotEnvLines($variable));
                }
            }

            return implode("\n", $lines) . "\n";
        }

        foreach ($variables as $variable) {
            if (!is_array($variable)) {
                continue;
            }
            $lines = array_merge($lines, $this->dotEnvLines($variable));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $variable
     *
     * @return list<string>
     */
    private function dotEnvLines(array $variable): array
    {
        try {
            $normalized = VariableSyntax::normalizeKey((string) ($variable['key'] ?? ''));
        } catch (InvalidArgumentException) {
            $normalized = (string) ($variable['key'] ?? '');
        }

        $key   = strtoupper(str_replace(['.', '-'], '_', $normalized));
        $value = (string) ($variable['value'] ?? '');
        if (isset($variable['description']) && $variable['description'] !== '') {
            return ['# ' . $variable['description'], $key . '=' . $this->escapeEnvValue($value)];
        }

        return [$key . '=' . $this->escapeEnvValue($value)];
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }
}
