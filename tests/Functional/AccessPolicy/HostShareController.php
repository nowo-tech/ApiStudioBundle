<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Symfony\Component\HttpFoundation\Response;

/**
 * A host page declared in `security.host_routes` (as Beacon's sharing page): the bundle guards the route, the
 * controller checks the workspace action itself.
 */
final readonly class HostShareController
{
    public function __construct(
        private ApiWorkspaceRepository $workspaces,
        private ApiStudioAuthorization $authorization,
    ) {
    }

    public function __invoke(int $id): Response
    {
        $workspace = $this->authorization->requireWorkspace($this->workspaces->find($id), ApiStudioAction::WorkspaceManage);

        return new Response('share ' . $workspace->getName());
    }
}
