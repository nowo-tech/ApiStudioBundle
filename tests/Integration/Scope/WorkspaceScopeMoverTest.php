<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Scope;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceScopeChangedEvent;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Scope\WorkspaceSlugTakenException;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.17: the host moves a workspace between scopes; the unique key decides a slug collision, the event follows the
 * commit.
 */
final class WorkspaceScopeMoverTest extends TestCase
{
    use ScopeOrmTrait;

    private ScopeManagerRegistry $registry;

    /** @var array<int, ApiWorkspaceScopeChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->registry = $this->sqliteScopeRegistry();
    }

    public function testMovesAWorkspaceIntoAScopeAndBack(): void
    {
        $id = $this->workspace('payments', null);

        $this->mover()->move($id, new WorkspaceScope('project:alpha'));
        self::assertSame('project:alpha', $this->reload($id)->getScope()?->value);
        self::assertCount(1, $this->events);
        self::assertSame([$id, null, 'project:alpha', 'org:acme'], [$this->events[0]->workspaceId, $this->events[0]->from, $this->events[0]->to, $this->events[0]->tenant->key()]);

        $this->mover()->move($id, null);
        self::assertNull($this->reload($id)->getScope());
        self::assertSame(['project:alpha', null], [$this->events[1]->from, $this->events[1]->to]);
    }

    public function testMovingToTheSameScopeChangesNothing(): void
    {
        $id = $this->workspace('payments', 'project:alpha');

        $this->mover()->move($id, new WorkspaceScope('project:alpha'));
        self::assertSame([], $this->events);
    }

    public function testASlugTakenInTheTargetScopeIsRefusedAndNothingChanges(): void
    {
        $this->workspace('payments', 'project:alpha');
        $id = $this->workspace('payments', null);

        try {
            $this->mover()->move($id, new WorkspaceScope('project:alpha'));
            self::fail('Expected the slug collision.');
        } catch (WorkspaceSlugTakenException $e) {
            self::assertSame($id, $e->workspaceId);
        }
        self::assertNull($this->reload($id)->getScope());
        self::assertSame([], $this->events);
    }

    public function testAnUnknownWorkspaceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mover()->move(999, null);
    }

    public function testScopesMustBeOn(): void
    {
        $this->expectException(LogicException::class);
        (new WorkspaceScopeMover($this->registry))->move(1, null);
    }

    private function mover(): WorkspaceScopeMover
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ApiWorkspaceScopeChangedEvent::class, function (ApiWorkspaceScopeChangedEvent $event): void {
            $this->events[] = $event;
        });

        return new WorkspaceScopeMover($this->registry, null, true, $dispatcher);
    }

    private function workspace(string $slug, ?string $scope): int
    {
        $em        = $this->scopeEntityManager($this->registry);
        $workspace = (new ApiWorkspace('W', $slug))->assignTenant(new ApiStudioTenant('org', 'acme'))->assignScope($scope !== null ? new WorkspaceScope($scope) : null);
        $em->persist($workspace);
        $em->flush();
        $em->clear();

        return (int) $workspace->getId();
    }

    private function reload(int $id): ApiWorkspace
    {
        $em = $this->scopeEntityManager($this->registry);
        $em->clear();
        $workspace = $em->find(ApiWorkspace::class, $id);
        self::assertInstanceOf(ApiWorkspace::class, $workspace);

        return $workspace;
    }
}
