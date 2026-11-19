<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tenant resolver double: the tenant it is given (null: none).
 */
final class SearchTenantResolverDouble implements ApiStudioTenantResolverInterface
{
    public function __construct(
        public ?ApiStudioTenant $tenant,
    ) {
    }

    public function resolve(Request $request): ?ApiStudioTenant
    {
        return $this->tenant;
    }
}
