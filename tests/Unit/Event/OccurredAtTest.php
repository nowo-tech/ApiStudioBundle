<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceDeletedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceMovedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceScopeChangedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceSeededEvent;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

use function count;

/**
 * 1.19 (DDD-host checklist B4): every domain event carries `occurredAt`, set when the bundle builds it, as a trailing
 * optional argument (the 1.18 constructors keep working), and the entity members of the in-transaction created event
 * are deprecated in favour of the ids.
 */
final class OccurredAtTest extends TestCase
{
    public function testEveryEventCarriesWhenItHappened(): void
    {
        $tenant = new ApiStudioTenant('org', 'acme');
        $before = new DateTimeImmutable();
        $events = [
            new ApiRequestExecutedEvent(1, 2, 3, 4, 5, 'GET', 'api.example.test', 200, 12, true),
            new ApiStudioResourceChangedEvent(ApiStudioResourceChangedEvent::TYPE_ENDPOINT, 1, 3, ApiStudioResourceChangedEvent::ACTION_UPDATED),
            new ApiWorkspaceCreatedCommittedEvent(3, 'payments', $tenant),
            new ApiWorkspaceCreatedEvent(new ApiWorkspace('Payments', 'payments'), $tenant),
            new ApiWorkspaceDeletedEvent(3, $tenant),
            new ApiWorkspaceMovedEvent(3, $tenant, ApiStudioTenant::instance()),
            new ApiWorkspaceScopeChangedEvent(3, $tenant, null, 'project:1'),
            new ApiWorkspaceSeededEvent(3, $tenant, null, true),
        ];

        foreach ($events as $event) {
            self::assertGreaterThanOrEqual($before, $event->occurredAt, $event::class);
            self::assertLessThanOrEqual(new DateTimeImmutable(), $event->occurredAt, $event::class);
            $parameters = (new ReflectionClass($event))->getConstructor()?->getParameters() ?? [];
            $last       = $parameters[count($parameters) - 1];
            self::assertSame('occurredAt', $last->getName(), $event::class . ': appended last (BC)');
            self::assertTrue($last->isOptional());
            $type = $last->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame(DateTimeImmutable::class, $type->getName());
        }
    }

    public function testAHostMayPassTheMoment(): void
    {
        $at = new DateTimeImmutable('2026-11-22 10:00:00');

        self::assertSame($at, (new ApiWorkspaceDeletedEvent(3, ApiStudioTenant::instance(), null, $at))->occurredAt);
    }

    public function testEntityMembersOfTheCreatedEventAreDeprecated(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ApiWorkspaceCreatedEvent::class))->getFileName());

        self::assertStringContainsString('@deprecated since 1.19, use $workspaceId', $source);
        self::assertStringContainsString('@deprecated since 1.19, use $actorId', $source);
    }
}
