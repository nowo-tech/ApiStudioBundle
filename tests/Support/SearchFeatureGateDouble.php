<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Security\ApiStudioFeatureGateInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Symfony\Component\HttpFoundation\Request;

/**
 * Feature gate double.
 */
final class SearchFeatureGateDouble implements ApiStudioFeatureGateInterface
{
    public function __construct(
        public bool $enabled,
    ) {
    }

    public function isEnabled(?object $user, ApiStudioTenant $tenant, Request $request): bool
    {
        return $this->enabled;
    }
}
