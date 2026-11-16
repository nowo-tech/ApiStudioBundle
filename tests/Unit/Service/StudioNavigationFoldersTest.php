<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function http_build_query;

/**
 * 1.14: the API tree nests endpoints in their folders (two extra scalar queries) and marks editable services.
 */
final class StudioNavigationFoldersTest extends TestCase
{
    public function testEndpointsNestInTheirFoldersAndEditableServicesGetTheMoveUrl(): void
    {
        $tree = $this->provider(true, null)->buildTree();

        $service = $tree[0]['services'][0];
        self::assertSame([['id' => 101, 'name' => 'Root call', 'method' => 'GET', 'url' => 'nowo_api_studio_endpoint_show?workspaceId=1&serviceId=10&id=101', 'request_examples' => []]], $service['endpoints']);
        self::assertSame('nowo_api_studio_folder_tree_move?workspaceId=1&serviceId=10', $service['move_url']);
        self::assertCount(1, $service['folders']);
        $users = $service['folders'][0];
        self::assertSame('Users', $users['name']);
        self::assertSame('nowo_api_studio_folder_edit?workspaceId=1&serviceId=10&id=1', $users['url']);
        self::assertSame(['Admin'], array_column($users['folders'], 'name'));
        self::assertSame([102], array_column($users['folders'][0]['endpoints'], 'id'));
    }

    public function testReadOnlyWorkspaceHasNoMoveUrl(): void
    {
        $checker = new class implements ApiStudioPermissionCheckerInterface {
            public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
            {
                return $action !== ApiStudioAction::WorkspaceEdit;
            }

            public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
            {
                return null;
            }
        };

        $tree = $this->provider(true, new ApiStudioAuthorization($checker))->buildTree();

        self::assertNull($tree[0]['services'][0]['move_url']);
    }

    public function testFoldersOffOrNoServicesKeepThe113Tree(): void
    {
        $tree = $this->provider(false, null)->buildTree();
        self::assertSame([], $tree[0]['services'][0]['folders']);
        self::assertCount(2, $tree[0]['services'][0]['endpoints']);

        $repository = $this->createStub(ApiWorkspaceRepository::class);
        $repository->method('navigationRows')->willReturn([$this->row(null, null)]);
        $folders = $this->createMock(ApiFolderRepository::class);
        $folders->expects(self::never())->method('treeRows');
        $tree = (new StudioNavigationProvider($repository, $this->urls(), null, $folders, true))->buildTree();
        self::assertSame([], $tree[0]['services']);
    }

    private function provider(bool $folders, ?ApiStudioAuthorization $authorization): StudioNavigationProvider
    {
        $repository = $this->createStub(ApiWorkspaceRepository::class);
        $repository->method('navigationRows')->willReturn([$this->row(101, 'Root call'), $this->row(102, 'Admin call')]);
        $workspace = new ApiWorkspace('W', 'w');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 1);
        $repository->method('find')->willReturn($workspace);

        $folderRepository = $this->createStub(ApiFolderRepository::class);
        $folderRepository->method('treeRows')->willReturn([
            ['id' => 1, 'name' => 'Users', 'parent' => null, 'service' => 10, 'workspace' => 1, 'sortOrder' => 0, 'createdBy' => null],
            ['id' => 2, 'name' => 'Admin', 'parent' => 1, 'service' => 10, 'workspace' => 1, 'sortOrder' => 0, 'createdBy' => null],
        ]);
        $folderRepository->method('endpointFolderIds')->willReturn([101 => null, 102 => 2]);

        return new StudioNavigationProvider($repository, $this->urls(), $authorization, $folderRepository, $folders);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?int $eid, ?string $name): array
    {
        return [
            'wid' => 1, 'wname' => 'W', 'wslug' => 'w',
            'sid' => $eid === null ? null : 10, 'sname' => 'S', 'sprotocol' => 'rest',
            'eid' => $eid, 'ename' => $name, 'emethod' => 'GET', 'xid' => null, 'xname' => null,
        ];
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params));

        return $urls;
    }
}
