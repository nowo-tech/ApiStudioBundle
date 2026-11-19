<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Search;

use Nowo\ApiStudioBundle\Event\ApiStudioSearchContextEvent;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;

final class ApiStudioSearchContextEventTest extends TestCase
{
    public function testDefaultsAndHostDecisions(): void
    {
        $event = new ApiStudioSearchContextEvent(SearchKind::Endpoint, 'p1', ApiStudioTenant::instance(), new WorkspaceScope('p1'));
        self::assertFalse($event->isDenied());
        self::assertSame('p1', $event->workspaceScope()?->value);
        self::assertSame([], $event->routeParameters());

        $event->excludeKind(SearchKind::Service);
        self::assertTrue($event->isExcluded(SearchKind::Service));
        self::assertFalse($event->isDenied(), 'another group is excluded');
        $event->excludeKind(SearchKind::Endpoint);
        self::assertTrue($event->isDenied());

        $other = new ApiStudioSearchContextEvent(SearchKind::Workspace, null, ApiStudioTenant::instance(), null);
        $other->setWorkspaceScope(null);
        $other->setTenant(new ApiStudioTenant('org', 'x'));
        self::assertFalse($other->isDenied());
        $other->setRouteParameters(['organization' => 'o1', 'workspaceId' => 9, 'serviceId' => 1, 'n' => 2.5, '' => 'x']);
        self::assertSame(['organization' => 'o1', 'n' => '2.5'], $other->routeParameters());
        $other->setTenant(null);
        self::assertTrue($other->isDenied());
        self::assertNull($other->tenant());

        self::assertTrue((new ApiStudioSearchContextEvent(SearchKind::Workspace, null, null, null))->isDenied());
        self::assertTrue((new ApiStudioSearchContextEvent(SearchKind::Workspace, 'x y', ApiStudioTenant::instance(), null, true))->isDenied());
        $denied = new ApiStudioSearchContextEvent(SearchKind::Workspace, null, ApiStudioTenant::instance(), null);
        $denied->deny();
        self::assertTrue($denied->isDenied());
    }
}
