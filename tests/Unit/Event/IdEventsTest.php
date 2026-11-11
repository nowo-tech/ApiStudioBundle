<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Event;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.12 (rules 1 and 4): ids next to the entities on the created event and the permission subject.
 */
final class IdEventsTest extends TestCase
{
    public function testCreatedEventCarriesIds(): void
    {
        $workspace = new ApiWorkspace('Payments', 'payments');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 42);
        $tenant = new ApiStudioTenant('organization', 'acme');

        $event = new ApiWorkspaceCreatedEvent($workspace, $tenant, null, 'u:alice');

        self::assertSame(42, $event->workspaceId);
        self::assertSame('payments', $event->slug);
        self::assertSame('u:alice', $event->actorId);
        self::assertSame($workspace, $event->workspace, 'the entity stays for standard hosts');
    }

    public function testCreatedEventKeepsTheThreeArgumentConstructor(): void
    {
        $event = new ApiWorkspaceCreatedEvent(new ApiWorkspace('Draft', 'draft'), ApiStudioTenant::instance());

        self::assertNull($event->workspaceId);
        self::assertNull($event->actorId);
        self::assertNull($event->user);
    }

    public function testSubjectCarriesTheWorkspaceId(): void
    {
        $workspace = new ApiWorkspace('Payments', 'payments');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 7);

        self::assertSame(7, (new ApiStudioSubject(ApiStudioTenant::instance(), $workspace))->workspaceId);
        self::assertNull((new ApiStudioSubject(ApiStudioTenant::instance()))->workspaceId);
    }
}
