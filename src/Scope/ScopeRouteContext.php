<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_starts_with;

/**
 * The matched route of an Api Studio request, as {@see ScopeResolverInterface} sees it (1.17): no HTTP type in the
 * port (REQ-ARCH-001 rule 3). `parameters` holds the scalar route attributes as strings (placeholders such as
 * `{project}`), without the framework's `_`-prefixed ones.
 */
final readonly class ScopeRouteContext
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public string $routeName,
        public array $parameters = [],
        /** True on a twin route mounted under `scope.path`. */
        public bool $scopedMount = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $attributes request attributes
     */
    public static function fromAttributes(string $routeName, array $attributes, bool $scopedMount): self
    {
        $parameters = [];
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || $name === '' || str_starts_with($name, '_')) {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value)) {
                $parameters[$name] = (string) $value;
            } elseif (is_bool($value)) {
                $parameters[$name] = $value ? '1' : '0';
            }
        }

        return new self($routeName, $parameters, $scopedMount);
    }

    public function parameter(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }
}
