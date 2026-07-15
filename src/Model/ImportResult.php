<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Model;

/**
 * Summary of an import operation.
 */
final class ImportResult
{
    /** @param list<string> $messages */
    public function __construct(
        public readonly int $servicesCreated = 0,
        public readonly int $endpointsCreated = 0,
        public readonly int $variablesCreated = 0,
        public readonly int $variablesUpdated = 0,
        public readonly int $environmentsCreated = 0,
        public readonly array $messages = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            servicesCreated: $this->servicesCreated + $other->servicesCreated,
            endpointsCreated: $this->endpointsCreated + $other->endpointsCreated,
            variablesCreated: $this->variablesCreated + $other->variablesCreated,
            variablesUpdated: $this->variablesUpdated + $other->variablesUpdated,
            environmentsCreated: $this->environmentsCreated + $other->environmentsCreated,
            messages: array_merge($this->messages, $other->messages),
        );
    }
}
