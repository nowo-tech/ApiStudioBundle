<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Symfony\Component\HttpFoundation\Request;

/**
 * Adapts a request-free {@see ApiStudioTenantProviderInterface} (`tenancy.provider`, 1.12) to the resolver the tenant
 * listener calls: the request is ignored.
 *
 * @internal
 */
final readonly class TenantProviderResolver implements ApiStudioTenantResolverInterface
{
    public function __construct(
        private ApiStudioTenantProviderInterface $provider,
    ) {
    }

    public function resolve(Request $request): ?ApiStudioTenant
    {
        return $this->provider->current();
    }
}
