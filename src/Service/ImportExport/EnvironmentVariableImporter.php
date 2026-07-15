<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Model\ImportResult;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

use function is_array;

/**
 * Imports environment variables from JSON, YAML, or .env format.
 */
final class EnvironmentVariableImporter
{
    public function __construct(
        private readonly DocumentParser $parser,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function importIntoEnvironment(
        ApiEnvironment $environment,
        string $content,
        string $mode,
        ?string $filename = null,
    ): ImportResult {
        if ($this->isDotEnv($content, $filename)) {
            return $this->importDotEnv($environment, $content, $mode);
        }

        $data   = $this->parser->parse($content, $filename);
        $format = (string) ($data['format'] ?? '');

        if ($format === 'api-studio-workspace-environments') {
            return $this->importWorkspacePayload($environment->getWorkspace(), $data, $mode, $environment);
        }

        return $this->importEnvironmentPayload($environment, $data, $mode);
    }

    public function importIntoWorkspace(
        ApiWorkspace $workspace,
        string $content,
        string $mode,
        ?string $filename = null,
    ): ImportResult {
        if ($this->isDotEnv($content, $filename)) {
            $environment = $this->resolveDefaultEnvironment($workspace);
            if (!$environment instanceof ApiEnvironment) {
                $environment = new ApiEnvironment('Imported', 'imported');
                $environment->setIsDefault(true);
                $workspace->addEnvironment($environment);
                $this->entityManager->persist($environment);
            }

            return $this->importDotEnv($environment, $content, $mode)
                ->merge(new ImportResult(environmentsCreated: $environment->getId() === null ? 1 : 0));
        }

        $data = $this->parser->parse($content, $filename);
        if (($data['format'] ?? '') === 'api-studio-workspace-environments') {
            return $this->importWorkspacePayload($workspace, $data, $mode);
        }

        $environment = $this->resolveDefaultEnvironment($workspace);
        if (!$environment instanceof ApiEnvironment) {
            $environment = new ApiEnvironment('Imported', 'imported');
            $environment->setIsDefault(true);
            $workspace->addEnvironment($environment);
            $this->entityManager->persist($environment);
        }

        return $this->importEnvironmentPayload($environment, $data, $mode)
            ->merge(new ImportResult(environmentsCreated: 1));
    }

    /** @param array<string, mixed> $data */
    private function importWorkspacePayload(
        ?ApiWorkspace $workspace,
        array $data,
        string $mode,
        ?ApiEnvironment $singleTarget = null,
    ): ImportResult {
        if (!$workspace instanceof ApiWorkspace) {
            throw new InvalidArgumentException('Workspace is required for multi-environment import.');
        }

        $result = new ImportResult();
        foreach ($data['environments'] ?? [] as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            if ($singleTarget instanceof ApiEnvironment) {
                $result = $result->merge($this->importEnvironmentPayload($singleTarget, $payload, $mode));

                continue;
            }

            $envMeta     = $payload['environment'] ?? [];
            $slug        = SlugHelper::fromString((string) ($envMeta['slug'] ?? $envMeta['name'] ?? 'imported'));
            $environment = $this->findEnvironmentBySlug($workspace, $slug);
            $created     = false;
            if (!$environment instanceof ApiEnvironment) {
                $environment = new ApiEnvironment(
                    (string) ($envMeta['name'] ?? ucfirst($slug)),
                    $slug,
                );
                $environment->setIsDefault((bool) ($envMeta['is_default'] ?? false));
                $workspace->addEnvironment($environment);
                $this->entityManager->persist($environment);
                $created = true;
            }

            $result = $result->merge($this->importEnvironmentPayload($environment, $payload, $mode));
            if ($created) {
                $result = $result->merge(new ImportResult(environmentsCreated: 1));
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function importEnvironmentPayload(ApiEnvironment $environment, array $data, string $mode): ImportResult
    {
        if ($mode === 'replace') {
            foreach ($environment->getVariables()->toArray() as $existing) {
                $environment->removeVariable($existing);
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($data['variables'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            try {
                $key = VariableSyntax::normalizeKey(trim((string) ($row['key'] ?? '')));
            } catch (InvalidArgumentException) {
                continue;
            }

            $variable = $this->findVariable($environment, $key);
            if (!$variable instanceof ApiEnvironmentVariable) {
                $variable = new ApiEnvironmentVariable($key, (string) ($row['value'] ?? ''));
                $environment->addVariable($variable);
                ++$created;
            } else {
                ++$updated;
            }

            $variable->setValue((string) ($row['value'] ?? ''));
            $variable->setSecret((bool) ($row['secret'] ?? false));
            $variable->setDescription(isset($row['description']) ? (string) $row['description'] : null);
        }

        return new ImportResult(variablesCreated: $created, variablesUpdated: $updated);
    }

    private function importDotEnv(ApiEnvironment $environment, string $content, string $mode): ImportResult
    {
        if ($mode === 'replace') {
            foreach ($environment->getVariables()->toArray() as $existing) {
                $environment->removeVariable($existing);
            }
        }

        $created = 0;
        $updated = 0;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$rawKey, $rawValue] = explode('=', $line, 2);
            try {
                $key = VariableSyntax::normalizeKey(strtolower(trim(str_replace('-', '_', $rawKey))));
            } catch (InvalidArgumentException) {
                continue;
            }
            $value = trim($rawValue, " \t\"'");

            $variable = $this->findVariable($environment, $key);
            if (!$variable instanceof ApiEnvironmentVariable) {
                $variable = new ApiEnvironmentVariable($key, $value);
                $environment->addVariable($variable);
                ++$created;
            } else {
                $variable->setValue($value);
                ++$updated;
            }
        }

        return new ImportResult(variablesCreated: $created, variablesUpdated: $updated);
    }

    private function isDotEnv(string $content, ?string $filename): bool
    {
        if ($filename !== null && str_ends_with(strtolower($filename), '.env')) {
            return true;
        }

        $trimmed = ltrim($content);

        return !str_starts_with($trimmed, '{') && !str_starts_with($trimmed, 'openapi:') && !str_starts_with($trimmed, 'swagger:');
    }

    private function findVariable(ApiEnvironment $environment, string $key): ?ApiEnvironmentVariable
    {
        foreach ($environment->getVariables() as $variable) {
            if ($variable->getVariableKey() === $key) {
                return $variable;
            }
        }

        return null;
    }

    private function findEnvironmentBySlug(ApiWorkspace $workspace, string $slug): ?ApiEnvironment
    {
        foreach ($workspace->getEnvironments() as $environment) {
            if ($environment->getSlug() === $slug) {
                return $environment;
            }
        }

        return null;
    }

    private function resolveDefaultEnvironment(ApiWorkspace $workspace): ?ApiEnvironment
    {
        foreach ($workspace->getEnvironments() as $environment) {
            if ($environment->isDefault()) {
                return $environment;
            }
        }

        return $workspace->getEnvironments()->first() ?: null;
    }
}
