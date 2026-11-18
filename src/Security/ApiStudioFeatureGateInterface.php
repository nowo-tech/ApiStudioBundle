<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host contract (`nowo_api_studio.security.feature_gate`, 1.16): whether Api Studio is available at all for this
 * tenant and user — typically a plan / billing feature. Consulted by the access subscriber on every Api Studio route
 * (the bundle's `nowo_api_studio_*` and the host's `security.host_routes`), after the role check, the access checker
 * and `studio.open`, before the controller. false denies with `security.access_denied_response` (403 by default, or a
 * 404 identical to a genuine not-found).
 *
 * Deny by default: return true only when the feature is on. An exception propagates (the request fails closed).
 * Implementations run in long-lived workers: keep no per-request state in the service.
 */
interface ApiStudioFeatureGateInterface
{
    public function isEnabled(?object $user, ApiStudioTenant $tenant, Request $request): bool;
}
