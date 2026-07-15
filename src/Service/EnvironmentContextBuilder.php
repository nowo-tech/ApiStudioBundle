<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

/**
 * Builds environment variable context for UI preview and documentation.
 */
final class EnvironmentContextBuilder
{
    /**
     * @return array<int, array{name: string, is_default: bool, variables: array<string, string>}>
     */
    public function buildMaps(ApiWorkspace $workspace): array
    {
        $maps = [];
        foreach ($workspace->getEnvironments() as $environment) {
            $id = $environment->getId();
            if ($id === null) {
                continue;
            }

            $maps[$id] = [
                'name'       => $environment->getName(),
                'is_default' => $environment->isDefault(),
                'variables'  => $environment->getVariableMap(),
            ];
        }

        return $maps;
    }

    /**
     * Variable keys available in the workspace (union of all environments).
     *
     * @return list<array{key: string, secret: bool, description: ?string}>
     */
    public function buildVariableCatalog(ApiWorkspace $workspace): array
    {
        /** @var array<string, array{key: string, secret: bool, description: ?string}> $catalog */
        $catalog = [];

        foreach ($workspace->getEnvironments() as $environment) {
            foreach ($environment->getVariables() as $variable) {
                $key = $variable->getVariableKey();
                if (isset($catalog[$key])) {
                    continue;
                }

                $catalog[$key] = [
                    'key'         => $key,
                    'secret'      => $variable->isSecret(),
                    'description' => $variable->getDescription(),
                ];
            }
        }

        usort($catalog, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return array_values($catalog);
    }

    /** @return array<string, string> */
    public function resolveMapForEnvironment(?ApiEnvironment $environment): array
    {
        return $environment?->getVariableMap() ?? [];
    }
}
