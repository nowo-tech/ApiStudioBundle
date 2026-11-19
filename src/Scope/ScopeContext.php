<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The host scope of the work being done (1.17): the one {@see ScopeListener} resolved for the current main request,
 * else the one set by {@see self::runAs()} (commands, host services), else none (tenant level). The request value lives
 * on the request (worker-safe); the runAs value is restored in `finally` and cleared by {@see self::reset()}.
 */
final class ScopeContext implements ResetInterface
{
    public const REQUEST_ATTRIBUTE = '_nowo_api_studio_host_scope';

    private bool $overridden = false;

    private ?WorkspaceScope $override = null;

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly bool $enabled = false,
    ) {
    }

    /**
     * True when `nowo_api_studio.scope.enabled` is on (scope column mapped).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function current(): ?WorkspaceScope
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->overridden) {
            return $this->override;
        }

        $scope = $this->requestStack?->getMainRequest()?->attributes->get(self::REQUEST_ATTRIBUTE);

        return $scope instanceof WorkspaceScope ? $scope : null;
    }

    /**
     * Runs $callback in $scope (null = tenant level), then restores the previous value.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runAs(?WorkspaceScope $scope, callable $callback): mixed
    {
        $previous = [$this->overridden, $this->override];
        // @igor-ignore - Restored in finally and by reset(); never outlives the call.
        $this->overridden = true;
        // @igor-ignore - Restored in finally and by reset(); never outlives the call.
        $this->override = $scope;
        try {
            return $callback();
        } finally {
            // @igor-ignore - Restores the value of the enclosing call.
            [$this->overridden, $this->override] = $previous;
        }
    }

    public function reset(): void
    {
        // @igor-ignore - ResetInterface clears it between worker requests.
        $this->overridden = false;
        // @igor-ignore - ResetInterface clears it between worker requests.
        $this->override = null;
    }
}
