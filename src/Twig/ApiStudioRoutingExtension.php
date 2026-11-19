<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Twig;

use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * 1.17 Twig functions of the workspace scopes:
 * - `api_studio_path(name, params)`: `path()` for bundle routes that stays inside a `scope.path` mount (the bundle's
 *   templates use it; host templates that override them should too);
 * - `nowo_api_studio_scope()`: the host scope of the request, null at tenant level.
 *
 * Stateless: reads the current request through its services per call (worker-safe).
 */
final class ApiStudioRoutingExtension extends AbstractExtension
{
    public function __construct(
        private readonly ApiStudioUrlGenerator $urls,
        private readonly ScopeContext $scopeContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('api_studio_path', $this->path(...)),
            new TwigFunction('nowo_api_studio_scope', $this->scope(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function path(string $name, array $parameters = []): string
    {
        return $this->urls->generate($name, $parameters);
    }

    public function scope(): ?WorkspaceScope
    {
        return $this->scopeContext->current();
    }
}
