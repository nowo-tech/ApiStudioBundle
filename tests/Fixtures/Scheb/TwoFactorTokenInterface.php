<?php

declare(strict_types=1);

namespace Scheb\TwoFactorBundle\Security\Authentication\Token;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Stand-in for scheb/2fa's token of a login waiting for its second factor (the bundle is not a dependency): the
 * access policy compares tokens with this interface by name.
 */
interface TwoFactorTokenInterface extends TokenInterface
{
}
