<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioFolderRef;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function count;

/**
 * 1.14 folder sharing hook: the host checker can only narrow, a denial applies below, decisions memoised per request.
 */
final class FolderAccessTest extends TestCase
{
    /** @var list<array{string, int, list<int>}> */
    private array $asked = [];

    public function testWithoutCheckerEverythingFollowsTheWorkspace(): void
    {
        $access                 = new FolderAccess();
        [, , $child, $endpoint] = $this->tree();

        self::assertFalse($access->isActive());
        self::assertTrue($access->can(ApiStudioAction::WorkspaceEdit, $child));
        self::assertSame($endpoint, $access->requireEndpoint($endpoint, ApiStudioAction::WorkspaceEdit));
        self::assertSame([], $access->hiddenFolderIds([['id' => 1, 'parent' => null, 'service' => 1, 'workspace' => 1, 'createdBy' => null]]));
    }

    public function testHiddenAncestorHidesEverythingBelowAs404(): void
    {
        [, $top, $child, $endpoint] = $this->tree();
        $access                     = $this->access(static fn (ApiStudioAction $action, ApiStudioFolderRef $folder): bool => $folder->folderId !== 10);

        self::assertFalse($access->can(ApiStudioAction::WorkspaceView, $child));
        self::assertFalse($access->canEndpoint(ApiStudioAction::WorkspaceView, $endpoint));

        $this->expectException(NotFoundHttpException::class);
        $access->requireEndpoint($endpoint);
    }

    public function testReadOnlyFolderAnswers403ForEdits(): void
    {
        [, , $child, $endpoint] = $this->tree();
        $access                 = $this->access(static fn (ApiStudioAction $action, ApiStudioFolderRef $folder): bool => !($folder->folderId === 11 && $action === ApiStudioAction::WorkspaceEdit));

        self::assertSame($child, $access->requireFolder($child));
        self::assertSame($endpoint, $access->requireEndpoint($endpoint, ApiStudioAction::WorkspaceRun));

        $this->expectException(AccessDeniedHttpException::class);
        $access->requireFolder($child, ApiStudioAction::WorkspaceEdit);
    }

    public function testRefCarriesIdsAncestorsAndCreatorAndDecisionsAreMemoisedPerRequest(): void
    {
        [, , $child] = $this->tree();
        $stack       = new RequestStack();
        $stack->push(new Request());
        $access = $this->access(static fn (): bool => true, $stack);

        $access->can(ApiStudioAction::WorkspaceView, $child);
        $access->can(ApiStudioAction::WorkspaceView, $child);

        self::assertSame([['workspace.view', 10, []], ['workspace.view', 11, [10]]], $this->asked);

        // A new main request (worker: the next request) asks again.
        $stack->pop();
        $stack->push(new Request());
        $access->can(ApiStudioAction::WorkspaceView, $child);
        self::assertSame(4, $this->askedCount());
    }

    public function testHiddenFolderIdsFromTreeRows(): void
    {
        $access = $this->access(static fn (ApiStudioAction $action, ApiStudioFolderRef $folder): bool => $folder->folderId !== 2);
        $rows   = [
            ['id' => 1, 'parent' => null, 'service' => 1, 'workspace' => 1, 'createdBy' => null],
            ['id' => 2, 'parent' => 1, 'service' => 1, 'workspace' => 1, 'createdBy' => 'u:ana'],
            ['id' => 3, 'parent' => 2, 'service' => 1, 'workspace' => 1, 'createdBy' => null],
            ['id' => 4, 'parent' => 1, 'service' => 1, 'workspace' => 1, 'createdBy' => null],
        ];

        self::assertSame([2 => true, 3 => true], $access->hiddenFolderIds($rows));

        // A corrupt cycle (never stored) does not loop.
        $cycle = [
            ['id' => 5, 'parent' => 6, 'service' => 1, 'workspace' => 1, 'createdBy' => null],
            ['id' => 6, 'parent' => 5, 'service' => 1, 'workspace' => 1, 'createdBy' => null],
        ];
        self::assertSame([], $access->hiddenFolderIds($cycle));
    }

    private function askedCount(): int
    {
        return count($this->asked);
    }

    /**
     * @param callable(ApiStudioAction, ApiStudioFolderRef): bool $decide
     */
    private function access(callable $decide, ?RequestStack $stack = null): FolderAccess
    {
        $record = function (ApiStudioAction $action, ApiStudioFolderRef $folder): void {
            $this->asked[] = [$action->value, $folder->folderId, $folder->ancestorIds];
        };
        $checker = new class($decide, $record) implements ApiStudioFolderPermissionCheckerInterface {
            /** @var callable(ApiStudioAction, ApiStudioFolderRef): bool */
            private $decide;

            /** @var callable(ApiStudioAction, ApiStudioFolderRef): void */
            private $record;

            public function __construct(callable $decide, callable $record)
            {
                $this->decide = $decide;
                $this->record = $record;
            }

            public function isFolderGranted(?object $user, ApiStudioAction $action, ApiStudioFolderRef $folder): bool
            {
                ($this->record)($action, $folder);

                return ($this->decide)($action, $folder);
            }
        };

        return new FolderAccess($checker, null, $stack);
    }

    /**
     * @return array{ApiService, ApiFolder, ApiFolder, ApiEndpoint}
     */
    private function tree(): array
    {
        $workspace = new ApiWorkspace('W', 'w');
        $service   = new ApiService('S', 's');
        $workspace->addService($service);
        $top      = (new ApiFolder('Top'))->setService($service);
        $child    = (new ApiFolder('Child'))->setService($service)->setParent($top)->setCreatedBy('u:ana');
        $endpoint = (new ApiEndpoint('E', 'e'))->setService($service)->setFolder($child);
        $id       = new ReflectionProperty(ApiFolder::class, 'id');
        $id->setValue($top, 10);
        $id->setValue($child, 11);

        return [$service, $top, $child, $endpoint];
    }
}
