<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function preg_replace;
use function strtolower;
use function trim;

/**
 * Seeds a newly created workspace with the environments listed in `nowo_api_studio.environments`.
 * The first configured environment becomes the workspace default. An empty list disables seeding.
 */
final class WorkspaceEnvironmentInitializer
{
    /**
     * @param list<string> $defaultEnvironmentNames
     */
    public function __construct(
        private readonly array $defaultEnvironmentNames = [],
    ) {
    }

    public function initialize(ApiWorkspace $workspace): void
    {
        if (!$workspace->getEnvironments()->isEmpty()) {
            return;
        }

        $seen = [];
        foreach ($this->defaultEnvironmentNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $environment = new ApiEnvironment($name, $slug);
            $environment->setIsDefault($seen === []);
            $workspace->addEnvironment($environment);
            $seen[$slug] = true;
        }
    }
}
