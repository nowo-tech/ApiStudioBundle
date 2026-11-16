<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Script\Runner\ScriptSource;

use function array_reverse;
use function trim;

/**
 * The scripts of an endpoint's pre-request and post-response phases, in run order and with the labels the console logs
 * (spec 003 US4, 1.15), for runs outside the browser through {@see Runner\ApiStudioScriptRunnerInterface}:
 *
 * - pre-request: workspace → service → folders root to leaf → endpoint;
 * - post-response: endpoint → folders leaf to root → service → workspace.
 *
 * Workspace and folder scripts are part of the chain only when they exist (`scripts.mode: sandbox`); empty scripts are
 * left out. Stateless: safe in a FrankenPHP worker with kernel reset off.
 */
final readonly class ScriptChainBuilder
{
    public function __construct(private bool $scriptLevels = false)
    {
    }

    /**
     * @param string|null $endpointScript the endpoint's pre-request script to use instead of the stored one (e.g. edited
     *                                    in a run definition); null = the stored one
     *
     * @return list<ScriptSource>
     */
    public function preRequest(ApiEndpoint $endpoint, ?string $endpointScript = null): array
    {
        $service   = $endpoint->getService();
        $workspace = $service?->getWorkspace();
        $chain     = [];
        if ($this->scriptLevels) {
            $chain[] = new ScriptSource('workspace pre', (string) $workspace?->getPreRequestScript());
        }
        $chain[] = new ScriptSource('service pre', (string) $service?->getPreRequestScript());
        foreach ($this->folders($endpoint) as $folder) {
            $chain[] = new ScriptSource('folder ' . $folder->getName() . ' pre', (string) $folder->getPreRequestScript());
        }
        $chain[] = new ScriptSource('pre-request', $endpointScript ?? (string) $endpoint->getPreRequestScript());

        return self::withoutEmpty($chain);
    }

    /**
     * @param string|null $endpointScript the endpoint's post-response script to use instead of the stored one
     *
     * @return list<ScriptSource>
     */
    public function postResponse(ApiEndpoint $endpoint, ?string $endpointScript = null): array
    {
        $service   = $endpoint->getService();
        $workspace = $service?->getWorkspace();
        $chain     = [new ScriptSource('post-request', $endpointScript ?? (string) $endpoint->getPostRequestScript())];
        foreach (array_reverse($this->folders($endpoint)) as $folder) {
            $chain[] = new ScriptSource('folder ' . $folder->getName() . ' post', (string) $folder->getPostRequestScript());
        }
        $chain[] = new ScriptSource('service post', (string) $service?->getPostRequestScript());
        if ($this->scriptLevels) {
            $chain[] = new ScriptSource('workspace post', (string) $workspace?->getPostRequestScript());
        }

        return self::withoutEmpty($chain);
    }

    /**
     * Folders of the endpoint from the top level down (none without script levels).
     *
     * @return list<ApiFolder>
     */
    private function folders(ApiEndpoint $endpoint): array
    {
        return $this->scriptLevels ? ($endpoint->getFolder()?->getPath() ?? []) : [];
    }

    /**
     * @param list<ScriptSource> $chain
     *
     * @return list<ScriptSource>
     */
    private static function withoutEmpty(array $chain): array
    {
        $kept = [];
        foreach ($chain as $script) {
            if (trim($script->code) !== '') {
                $kept[] = $script;
            }
        }

        return $kept;
    }
}
