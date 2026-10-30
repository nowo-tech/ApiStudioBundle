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
     * exported), each variable as KEY=value (key upper-cased, dots and dashes as underscores) after its description.
     *
     * @param iterable<ApiEnvironment> $environments
     */
    private function toDotEnv(iterable $environments, bool $workspace): string
    {
        $lines = ['# API Studio environment export'];
        foreach ($environments as $environment) {
            if ($workspace) {
                $lines[] = '';
                $lines[] = '# ' . $environment->getName();
            } else {
                $lines[] = '# Environment: ' . $environment->getName();
            }

            foreach ($environment->getVariables() as $variable) {
                $description = $variable->getDescription();
                if ($description !== null && $description !== '') {
                    $lines[] = '# ' . $description;
                }
                $key     = strtoupper(str_replace(['.', '-'], '_', $variable->getVariableKey()));
                $lines[] = $key . '=' . $this->escapeEnvValue($variable->getValue());
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }
}
