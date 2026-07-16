<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use PHPUnit\Framework\TestCase;

final class BundleConfigurationTest extends TestCase
{
    public function testBundleExposesExtensionAlias(): void
    {
        $bundle    = new ApiStudioBundle();
        $extension = $bundle->getContainerExtension();

        self::assertSame('nowo_api_studio', $extension->getAlias());
    }
}
