<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;

/**
 * The per-workspace, per-action decisions ({@see ApiStudioAuthorization}) from the controller's service locator. A
 * controller built by hand without it (unit tests) gets the default: tenant-less, everything granted (1.10 behaviour).
 */
trait AuthorizesTrait
{
    private function authorization(): ApiStudioAuthorization
    {
        if ($this->container->has(ApiStudioAuthorization::class)) {
            /** @var ApiStudioAuthorization $authorization */
            $authorization = $this->container->get(ApiStudioAuthorization::class);

            return $authorization;
        }

        return new ApiStudioAuthorization();
    }

    /**
     * 1.16: the 403 / 404 policy of refused actions (default 403 when built by hand).
     */
    private function accessPolicy(): ApiStudioAccessPolicy
    {
        if ($this->container->has(ApiStudioAccessPolicy::class)) {
            /** @var ApiStudioAccessPolicy $policy */
            $policy = $this->container->get(ApiStudioAccessPolicy::class);

            return $policy;
        }

        return new ApiStudioAccessPolicy();
    }

    private function historyScope(): ApiStudioHistoryScope
    {
        if ($this->container->has(ApiStudioHistoryScope::class)) {
            /** @var ApiStudioHistoryScope $scope */
            $scope = $this->container->get(ApiStudioHistoryScope::class);

            return $scope;
        }

        return new ApiStudioHistoryScope();
    }
}
