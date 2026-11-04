<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Tenancy;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * One tenant per `{org}` of the URL; `nobody` has no tenant (404).
 */
final class TestTenantResolver implements ApiStudioTenantResolverInterface
{
    public function resolve(Request $request): ?ApiStudioTenant
    {
        $org = $request->attributes->get('org');

        return is_string($org) && $org !== 'nobody' ? new ApiStudioTenant('org', $org) : null;
    }
}
