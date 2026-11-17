<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

/**
 * `access_denied_response: 404`: every refusal is the genuine 404 of its route.
 */
final class AccessPolicy404Test extends AccessPolicyTestCase
{
    protected const STATUS = 404;
}
