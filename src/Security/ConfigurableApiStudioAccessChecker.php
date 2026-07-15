<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_api_studio.security.* config.
 */
final readonly class ConfigurableApiStudioAccessChecker implements ApiStudioAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canAccess(object $user): bool
    {
        if ($this->accessRoles === []) {
            return true;
        }

        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
