<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

/**
 * Host contract (`nowo_api_studio.tenancy.provider`, 1.12): the current tenant, without an HTTP request — for hosts
 * whose application layer already knows it (DDD application context, a tenant held by a security token, a message
 * envelope). A request-free alternative to {@see ApiStudioTenantResolverInterface}; set one of the two.
 *
 * Called once per Api Studio main request, right after routing. Identify only, never authorize. Return null when
 * there is no current tenant: the bundle answers 404. Worker mode: read the tenant per call, never cache it in a
 * property across requests.
 */
interface ApiStudioTenantProviderInterface
{
    public function current(): ?ApiStudioTenant;
}
