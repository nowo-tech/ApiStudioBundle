<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Model;

/**
 * Outcome of resolving the host secret references of one execution (1.13). Lives for that execution only.
 *
 * @internal
 */
final readonly class HostSecretResolution
{
    /**
     * @param array<string, string> $variables the variable map with the resolved values (unresolved keys removed)
     * @param list<string> $values the resolved values, to mask everywhere but in the outbound request
     * @param list<string> $unresolvedKeys keys whose reference the host did not resolve
     */
    public function __construct(
        public array $variables,
        public array $values = [],
        public array $unresolvedKeys = [],
    ) {
    }
}
