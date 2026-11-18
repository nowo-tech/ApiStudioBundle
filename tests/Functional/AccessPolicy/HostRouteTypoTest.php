<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * 1.16 (audit SY-L1): a `security.host_routes` key that is not a route stops the kernel when it builds its cache, so
 * a typo can never leave a host page unguarded.
 */
final class HostRouteTypoTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return AccessPolicyTestKernel::class;
    }

    public function testAnUnknownHostRouteFailsTheWarmUp(): void
    {
        (new Filesystem())->remove(AccessPolicyTestKernel::baseDir() . '/cache/policy_typo');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"host_studio_typo"');

        self::bootKernel(['environment' => 'policy_typo', 'debug' => false]);
    }
}
