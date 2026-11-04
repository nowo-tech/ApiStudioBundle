<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The tenant of the work being done: the one {@see ApiStudioTenantListener} resolved for the current main request,
 * else the one set by {@see self::runAs()} (commands, seeds), else the instance tenant. The request value lives on the
 * request (worker-safe); the runAs value is cleared by {@see self::reset()}.
 */
final class ApiStudioTenantContext implements ResetInterface
{
    public const REQUEST_ATTRIBUTE = '_nowo_api_studio_tenant';

    private ?ApiStudioTenant $override = null;

    public function __construct(
        private readonly ?RequestStack $requestStack = null,
        private readonly bool $enabled = false,
    ) {
    }

    /**
     * True when `nowo_api_studio.tenancy.enabled` is on (tenant columns mapped).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function current(): ApiStudioTenant
    {
        if ($this->override instanceof ApiStudioTenant) {
            return $this->override;
        }

        $tenant = $this->requestStack?->getMainRequest()?->attributes->get(self::REQUEST_ATTRIBUTE);

        return $tenant instanceof ApiStudioTenant ? $tenant : ApiStudioTenant::instance();
    }

    /**
     * Runs $callback as $tenant (CLI, seeds, imports from commands), then restores the previous value.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runAs(ApiStudioTenant $tenant, callable $callback): mixed
    {
        $previous = $this->override;
        // @igor-ignore - Restored in finally and by reset(); never outlives the call.
        $this->override = $tenant;
        try {
            return $callback();
        } finally {
            // @igor-ignore - Restores the value of the enclosing call.
            $this->override = $previous;
        }
    }

    public function reset(): void
    {
        // @igor-ignore - ResetInterface clears it between worker requests.
        $this->override = null;
    }
}
