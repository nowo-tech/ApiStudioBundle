<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Script\Runner;

/**
 * Result of one server-side phase (spec 003, 1.15). On anything but {@see ScriptRunStatus::Completed} the state is the
 * one the runner reported (or the request's when it reported none).
 */
final readonly class ScriptRunResult
{
    /**
     * @param array<string, string> $variables variables after the scripts
     * @param array<string, string> $changedVariables environment changes ('' = removed, as in the console)
     * @param array<string, string> $headers request headers after the scripts
     * @param array<string, string> $queryParams
     * @param list<string> $logs labelled log lines
     * @param list<ScriptTestResult> $tests
     * @param array<string, string> $globals
     * @param array<string, string> $collectionVariables
     * @param array<string, string|null> $globalChanges null = removed
     * @param array<string, string|null> $collectionChanges
     */
    public function __construct(
        public ScriptRunStatus $status,
        public ?string $error = null,
        public ?string $failedScript = null,
        public array $variables = [],
        public array $changedVariables = [],
        public string $body = '',
        public array $headers = [],
        public array $queryParams = [],
        public array $logs = [],
        public array $tests = [],
        public array $globals = [],
        public array $collectionVariables = [],
        public array $globalChanges = [],
        public array $collectionChanges = [],
    ) {
    }

    /**
     * A result that keeps the request's state (nothing ran, or the runner gave no usable answer).
     */
    public static function unchanged(ScriptRunRequest $request, ScriptRunStatus $status, ?string $error = null): self
    {
        return new self(
            status: $status,
            error: $error,
            variables: $request->variables,
            body: $request->body,
            headers: $request->headers,
            queryParams: $request->queryParams,
            globals: $request->globals,
            collectionVariables: $request->collectionVariables,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === ScriptRunStatus::Completed;
    }
}
