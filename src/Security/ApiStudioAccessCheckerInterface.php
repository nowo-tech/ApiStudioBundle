<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

/**
 * Global access control for Api Studio UI routes.
 */
interface ApiStudioAccessCheckerInterface
{
    public function canAccess(object $user): bool;
}
