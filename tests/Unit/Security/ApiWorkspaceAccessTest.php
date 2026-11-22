<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use ArrayObject;
use DateTimeImmutable;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceCriteria;
use Nowo\ApiStudioBundle\Query\ApiWorkspacePage;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceQueryInterface;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceView;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Security\ApiWorkspaceAccess;
use Nowo\ApiStudioBundle\Security\LegacyPermissionChecker;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\IdOnlyPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Support\IdOnlyScopeChecker;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function range;

/**
 * 1.19 (DDD-host checklist B1/B8): access to a workspace asked by ids. The port reads the workspace by exact id and
 * tenant, then asks the host's checkers with id-only subjects; every refusal and failure looks the same (no oracle);
 * nothing is kept between calls.
 */
final class ApiWorkspaceAccessTest extends TestCase
{
    private IdOnlyPermissionChecker $checker;

    private IdOnlyScopeChecker $scopes;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->checker                             = new IdOnlyPermissionChecker();
        $this->checker->refuseEntities             = true;
        $this->checker->grants['org:acme|u:alice'] = [
            7 => ['workspace.view', 'workspace.edit'],
            9 => ['workspace.view', 'workspace.run'],
        ];
        $this->scopes                              = new IdOnlyScopeChecker();
        $this->scopes->grants['u:alice|project:1'] = ['workspace.view', 'workspace.run'];
        $this->logger                              = new InMemoryLogger();
    }

    public function testDecidesOnIdsAlone(): void
    {
        $access = $this->access();

        self::assertTrue($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertTrue($access->isGranted('u:alice', ApiStudioAction::WorkspaceEdit, self::acme(), 7));
        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceManage, self::acme(), 7));
        self::assertFalse($access->isGranted('u:bob', ApiStudioAction::WorkspaceView, self::acme(), 7));

        $subject = $this->checker->subjects[0];
        self::assertSame(7, $subject->workspaceId);
        self::assertSame('u:alice', $subject->actorId);
        self::assertNull($subject->workspace);
        self::assertTrue($subject->tenant->equals(self::acme()));

        $view = $access->viewableWorkspace('u:alice', self::acme(), 7);
        self::assertInstanceOf(ApiWorkspaceView::class, $view);
        self::assertSame('payments', $view->slug);
        self::assertNull($access->viewableWorkspace('u:bob', self::acme(), 7));
    }

    public function testTheActionNeedsWorkspaceViewFirst(): void
    {
        $this->checker->grants['org:acme|u:carol'] = [7 => ['workspace.edit']];

        self::assertFalse($this->access()->isGranted('u:carol', ApiStudioAction::WorkspaceEdit, self::acme(), 7));
        self::assertCount(1, $this->checker->subjects, 'stopped at workspace.view');
    }

    /**
     * @return iterable<string, array{ApiStudioTenant, int}>
     */
    public static function refusedLookups(): iterable
    {
        yield 'unknown id' => [new ApiStudioTenant('org', 'acme'), 404];
        yield 'other tenant' => [new ApiStudioTenant('org', 'globex'), 7];
        yield 'look-alike tenant (case)' => [new ApiStudioTenant('org', 'ACME'), 7];
        yield 'look-alike tenant type' => [new ApiStudioTenant('organization', 'acme'), 7];
        yield 'instance tenant' => [ApiStudioTenant::instance(), 7];
        yield 'zero id' => [new ApiStudioTenant('org', 'acme'), 0];
        yield 'negative id' => [new ApiStudioTenant('org', 'acme'), -7];
    }

    #[DataProvider('refusedLookups')]
    public function testUnknownOtherTenantAndLookAlikesGiveTheSameRefusal(ApiStudioTenant $tenant, int $id): void
    {
        $access = $this->access();

        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, $tenant, $id));
        self::assertNull($access->viewableWorkspace('u:alice', $tenant, $id));
        self::assertSame([], $this->checker->subjects, 'no checker is asked: nothing to tell apart');
        self::assertSame([], $this->logger->records);
    }

    public function testAPortThatAnswersAnotherWorkspaceOrTenantIsNotTrusted(): void
    {
        $access = new ApiWorkspaceAccess(new class implements ApiWorkspaceQueryInterface {
            public function find(ApiWorkspaceCriteria $criteria): ApiWorkspacePage
            {
                return new ApiWorkspacePage([], 0, 1, 1);
            }

            public function count(ApiWorkspaceCriteria $criteria): int
            {
                return 0;
            }

            public function get(ApiStudioTenant $tenant, int $workspaceId): ApiWorkspaceView
            {
                // A broken store: a case-folded match and another id.
                return $workspaceId === 7 ? ApiWorkspaceAccessTest::view(7, new ApiStudioTenant('org', 'ACME')) : ApiWorkspaceAccessTest::view(8, $tenant);
            }

            public function workspaceIdOfService(ApiStudioTenant $tenant, int $serviceId): ?int
            {
                return null;
            }

            public function workspaceIdOfEndpoint(ApiStudioTenant $tenant, int $endpointId): ?int
            {
                return null;
            }
        }, $this->checker);

        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 9));
        self::assertSame([], $this->checker->subjects);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableActorIds(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['u:alice bob'];
        yield 'padded' => [' u:alice'];
        yield 'newline' => ["u:alice\n"];
        yield 'too long' => ['u:' . str_repeat('a', 63)];
        yield 'non ascii' => ['u:alicé'];
    }

    #[DataProvider('unusableActorIds')]
    public function testUnusableActorIdsAreRefused(string $actorId): void
    {
        $this->checker->grants['org:acme|' . $actorId] = [7 => ['workspace.view']];

        self::assertFalse($this->access()->isGranted($actorId, ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertSame([], $this->checker->subjects);
    }

    public function testTenantLevelActionsAreAlwaysRefused(): void
    {
        self::assertFalse($this->access()->isGranted('u:alice', ApiStudioAction::StudioOpen, self::acme(), 7));
        self::assertFalse($this->access()->isGranted('u:alice', ApiStudioAction::WorkspaceCreate, self::acme(), 7));
    }

    public function testAUserObjectIsCheckedWithItsActorIdAndTheVisibleList(): void
    {
        $alice = new InMemoryUser('alice', null);

        self::assertTrue($this->access()->isGranted($alice, ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertSame('u:alice', $this->checker->subjects[0]->actorId);

        // visibleWorkspaceIds() narrows a user object (as the studio's lists do).
        $this->checker->visible = [9];
        self::assertFalse($this->access()->isGranted($alice, ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertTrue($this->access()->isGranted($alice, ApiStudioAction::WorkspaceView, self::acme(), 9));
        // Anonymous: no actor id, the id-only checker denies.
        self::assertFalse($this->access()->isGranted(null, ApiStudioAction::WorkspaceView, self::acme(), 9));

        // A host resolver gives the actor id.
        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'pub-1';
            }
        };
        $this->checker->visible                  = null;
        $this->checker->grants['org:acme|pub-1'] = [7 => ['workspace.view']];
        $access                                  = new ApiWorkspaceAccess($this->store(), $this->checker, null, new ApiStudioHistoryScope('user', $resolver));
        self::assertTrue($access->isGranted($alice, ApiStudioAction::WorkspaceView, self::acme(), 7));
    }

    public function testTooManyVisibleIdsFailClosed(): void
    {
        $this->checker->visible = range(1, ApiStudioAuthorization::MAX_VISIBLE_IDS + 1);

        self::assertFalse($this->access()->isGranted(new InMemoryUser('alice', null), ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testAScopedWorkspaceNeedsTheScopeCheckerToo(): void
    {
        $this->checker->grants['org:acme|u:alice'][11] = ['workspace.view', 'workspace.run', 'workspace.edit'];
        $access                                        = $this->access();

        self::assertTrue($access->isGranted('u:alice', ApiStudioAction::WorkspaceRun, self::acme(), 11));
        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceEdit, self::acme(), 11), 'the scope checker denies edit');
        $subject = $this->scopes->subjects[0];
        self::assertSame('project:1', $subject->scope->value);
        self::assertSame(11, $subject->workspaceId);
        self::assertSame('u:alice', $subject->actorId);
        self::assertSame('project:1', $this->checker->subjects[0]->scope?->value);

        // Deny by default: without a scope checker no scoped workspace is granted.
        $unscoped = new ApiWorkspaceAccess($this->store(), $this->checker, null, logger: $this->logger);
        self::assertFalse($unscoped->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 11));
        self::assertTrue($unscoped->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 7));
    }

    public function testFailuresAreLoggedByClassAndLookLikeARefusal(): void
    {
        $this->checker->failure = new RuntimeException('secret detail of the host');
        $access                 = $this->access();

        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, self::acme(), 7));
        self::assertNull($access->viewableWorkspace('u:alice', self::acme(), 7));
        self::assertCount(2, $this->logger->records);
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exception']);
        self::assertStringNotContainsString('secret detail', $this->logger->text());
    }

    /**
     * @return iterable<string, array{object|string|null}>
     */
    public static function anyUser(): iterable
    {
        yield 'anonymous' => [null];
        yield 'actor id' => ['u:anyone'];
        yield 'user object' => [new InMemoryUser('alice', null)];
    }

    #[DataProvider('anyUser')]
    public function testWithoutAPermissionCheckerEveryCallIsRefused(object|string|null $user): void
    {
        foreach ([null, new LegacyPermissionChecker()] as $checker) {
            $logger = new InMemoryLogger();
            /** @var ArrayObject<int, int> $reads */
            $reads  = new ArrayObject();
            $access = new ApiWorkspaceAccess($this->store($reads), $checker, $this->scopes, logger: $logger);

            foreach ([7, 9, 404] as $id) {
                foreach ([ApiStudioAction::WorkspaceView, ApiStudioAction::WorkspaceManage] as $action) {
                    self::assertFalse($access->isGranted($user, $action, self::acme(), $id));
                }
                self::assertNull($access->viewableWorkspace($user, self::acme(), $id));
                self::assertFalse($access->isGranted($user, ApiStudioAction::WorkspaceView, new ApiStudioTenant('org', 'globex'), $id));
            }

            self::assertCount(0, $reads, 'fail closed before the store: the same answer for every id');
            self::assertCount(12, $logger->records, 'one warning per call');
            self::assertSame('warning', $logger->records[0]['level']);
            self::assertStringContainsString('security.permission_checker', $logger->records[0]['message']);
        }
    }

    public static function view(int $id, ApiStudioTenant $tenant, ?string $scope = null): ApiWorkspaceView
    {
        $at = new DateTimeImmutable('2026-11-22 10:00:00');

        return new ApiWorkspaceView($id, 'Payments ' . $id, $id === 7 ? 'payments' : 'w' . $id, null, true, $tenant, $scope, $at, $at);
    }

    private function access(): ApiWorkspaceAccess
    {
        return new ApiWorkspaceAccess($this->store(), $this->checker, $this->scopes, logger: $this->logger);
    }

    private static function acme(): ApiStudioTenant
    {
        return new ApiStudioTenant('org', 'acme');
    }

    /**
     * Workspaces 7 and 9 (tenant level) and 11 (`project:1`) of org:acme; exact keys. `get()` records the ids read.
     *
     * @param ArrayObject<int, int> $reads
     */
    private function store(ArrayObject $reads = new ArrayObject()): ApiWorkspaceQueryInterface
    {
        return new class($reads) implements ApiWorkspaceQueryInterface {
            /**
             * @param ArrayObject<int, int> $reads
             */
            public function __construct(private readonly ArrayObject $reads)
            {
            }

            public function find(ApiWorkspaceCriteria $criteria): ApiWorkspacePage
            {
                return new ApiWorkspacePage([], 0, 1, 1);
            }

            public function count(ApiWorkspaceCriteria $criteria): int
            {
                return 0;
            }

            public function get(ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView
            {
                $this->reads->append($workspaceId);
                if (!$tenant->equals(new ApiStudioTenant('org', 'acme'))) {
                    return null;
                }

                return match ($workspaceId) {
                    7, 9    => ApiWorkspaceAccessTest::view($workspaceId, $tenant),
                    11      => ApiWorkspaceAccessTest::view(11, $tenant, 'project:1'),
                    default => null,
                };
            }

            public function workspaceIdOfService(ApiStudioTenant $tenant, int $serviceId): ?int
            {
                return null;
            }

            public function workspaceIdOfEndpoint(ApiStudioTenant $tenant, int $endpointId): ?int
            {
                return null;
            }
        };
    }
}
