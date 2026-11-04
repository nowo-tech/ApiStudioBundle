<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use Symfony\Component\HttpFoundation\Request;

/**
 * Host contract (`nowo_api_studio.tenancy.resolver`): the tenant of an Api Studio request.
 *
 * Called once per main request, right after routing. Identify only, never authorize: whether the user may open the
 * tenant's studio is the permission checker's `studio.open`. Return null when the request belongs to no tenant: the
 * bundle answers 404.
 */
interface ApiStudioTenantResolverInterface
{
    public function resolve(Request $request): ?ApiStudioTenant;
}
