<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * The scripts of one phase (pre or post) and the state they see (spec 003, 1.15): variables as the console would hand
 * them (secret values as their own `{{placeholder}}`, never the value), the request, the response of a post phase (masked body and headers, round trip),
 * `pm.globals` / `pm.collectionVariables`.
 */
final readonly class ScriptRunRequest
{
    /**
     * @param list<ScriptSource> $scripts
     * @param array<string, string> $variables
     * @param array<string, string> $headers request headers
     * @param array<string, string> $queryParams
     * @param array<string, string> $globals
     * @param array<string, string> $collectionVariables
     * @param array<string, string> $responseHeaders response headers of a post phase, already masked (1.15)
     */
    public function __construct(
        public array $scripts,
        public array $variables = [],
        public string $body = '',
        public array $headers = [],
        public array $queryParams = [],
        public ?int $responseCode = null,
        public ?string $responseBody = null,
        public array $globals = [],
        public array $collectionVariables = [],
        public bool $runTests = false,
        public ScriptRunLimits $limits = new ScriptRunLimits(),
        public array $responseHeaders = [],
        public int $responseTimeMs = 0,
    ) {
    }
}
