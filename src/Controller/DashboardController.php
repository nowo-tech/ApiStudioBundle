<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '', name: 'nowo_api_studio_')]
final class DashboardController extends AbstractController
{
    use ApiStudioPageTrait;
    use AuthorizesTrait;

    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly int $listPageSize = 50,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        // REQ-PERF-001: bounded list + COUNT queries instead of hydrating every row.
        $scope          = $this->authorization()->scope();
        $workspaceCount = $this->workspaceRepository->countInScope($scope);
        $serviceCount   = $this->serviceRepository->countInScope($scope);
        $endpointCount  = $this->endpointRepository->countInScope($scope);

        return $this->renderPage('@NowoApiStudioBundle/dashboard/index.html.twig', [
            'page'           => $this->pages()->dashboard($workspaceCount, $serviceCount, $endpointCount),
            'workspaces'     => $this->workspaceRepository->findInScope($scope, $this->listPageSize),
            'workspaceCount' => $workspaceCount,
            'serviceCount'   => $serviceCount,
            'endpointCount'  => $endpointCount,
        ]);
    }
}
