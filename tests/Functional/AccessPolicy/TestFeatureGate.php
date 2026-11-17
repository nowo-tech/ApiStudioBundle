<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

use Nowo\ApiStudioBundle\Security\ApiStudioFeatureGateInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Symfony\Component\HttpFoundation\Request;

/**
 * The plan switch of the policy tests: off unless {@see self::$enabled}; records the route of every call. The test
 * drives one instance (the kernel is not rebooted between requests).
 */
final class TestFeatureGate implements ApiStudioFeatureGateInterface
{
    public bool $enabled = false;

    /** @var list<string> */
    public array $routes = [];

    /** @var list<string> */
    public array $tenants = [];

    public function isEnabled(?object $user, ApiStudioTenant $tenant, Request $request): bool
    {
        $this->routes[]  = (string) $request->attributes->get('_route');
        $this->tenants[] = $tenant->key();

        return $this->enabled;
    }
}
