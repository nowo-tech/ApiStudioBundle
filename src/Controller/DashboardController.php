<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function count;

#[Route(path: '', name: 'nowo_api_studio_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiEndpointRepository $endpointRepository,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@NowoApiStudioBundle/dashboard/index.html.twig', [
            'workspaces'    => $this->workspaceRepository->findBy([], ['name' => 'ASC']),
            'serviceCount'  => count($this->serviceRepository->findAll()),
            'endpointCount' => count($this->endpointRepository->findAll()),
        ]);
    }
}
