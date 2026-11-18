<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use LogicException;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;

use function sprintf;

/**
 * The per-workspace, per-action decisions ({@see ApiStudioAuthorization}) and the 403 / 404 policy from the
 * controller's service locator. Fail closed (1.16, audit SY-L2): a controller whose locator lacks them throws instead
 * of falling back to an everything-granted default — the controller must list them in getSubscribedServices().
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

        throw new LogicException(sprintf('%s must subscribe %s (getSubscribedServices()).', static::class, ApiStudioAuthorization::class));
    }

    /**
     * 1.16: the 403 / 404 policy of refused actions.
     */
    private function accessPolicy(): ApiStudioAccessPolicy
    {
        if ($this->container->has(ApiStudioAccessPolicy::class)) {
            /** @var ApiStudioAccessPolicy $policy */
            $policy = $this->container->get(ApiStudioAccessPolicy::class);

            return $policy;
        }

        throw new LogicException(sprintf('%s must subscribe %s (getSubscribedServices()).', static::class, ApiStudioAccessPolicy::class));
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
