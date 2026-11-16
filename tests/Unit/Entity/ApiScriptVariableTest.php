<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Entity;

use Nowo\ApiStudioBundle\Entity\ApiScriptVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Spec 003 (1.15): a script variable is a global (scope 0) or a collection variable (scope = service id).
 */
final class ApiScriptVariableTest extends TestCase
{
    public function testGlobalAndCollectionScopes(): void
    {
        $workspace = new ApiWorkspace('W', 'w');
        $global    = new ApiScriptVariable($workspace, null, '{{ host }}', 'example.org');
        self::assertNull($global->getId());
        self::assertSame($workspace, $global->getWorkspace());
        self::assertNull($global->getService());
        self::assertSame(0, $global->getScopeId());
        self::assertSame('host', $global->getVariableKey());
        self::assertSame('example.org', $global->getValue());

        $service = new ApiService('S', 's');
        (new ReflectionProperty(ApiService::class, 'id'))->setValue($service, 7);
        $collection = new ApiScriptVariable($workspace, $service, 'page', '1');
        self::assertSame($service, $collection->getService());
        self::assertSame(7, $collection->getScopeId());

        $before = $collection->getUpdatedAt();
        self::assertSame('2', $collection->setValue('2')->getValue());
        self::assertGreaterThanOrEqual($before, $collection->getUpdatedAt());
    }
}
