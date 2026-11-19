<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Contract;

use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use PHPUnit\Framework\TestCase;

use function class_exists;

if (class_exists(EntitySearchProviderTestCase::class)) {
    /**
     * SearchKit's contract kit (installed: PHP >= 8.3, `.github/ci/search-kit.sh`).
     */
    abstract class SearchKitContractBase extends EntitySearchProviderTestCase
    {
    }
} else {
    /**
     * SearchKit is optional and needs PHP >= 8.3: without it the contract tests are skipped.
     */
    abstract class SearchKitContractBase extends TestCase
    {
        public function testContractNeedsSearchKit(): void
        {
            self::markTestSkipped('nowo-tech/search-kit-bundle is not installed (it needs PHP >= 8.3).');
        }
    }
}
