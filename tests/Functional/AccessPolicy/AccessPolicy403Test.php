<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

/**
 * `access_denied_response: 403` (the default): refused actions answer 403.
 */
final class AccessPolicy403Test extends AccessPolicyTestCase
{
    protected const STATUS = 403;
}
