<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;

use function class_exists;

/**
 * SearchKit is optional and needs PHP >= 8.3 (`.github/ci/search-kit.sh` installs it on 8.3+): without it these tests
 * are skipped.
 */
trait SkipsWithoutSearchKit
{
    protected function skipWithoutSearchKit(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle is not installed (it needs PHP >= 8.3).');
        }
    }
}
