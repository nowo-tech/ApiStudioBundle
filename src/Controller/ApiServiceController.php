<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Form\ApiServiceFormType;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/workspaces/{workspaceId}/services', name: 'nowo_api_studio_service_')]
final class ApiServiceController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EnvironmentContextBuilder $environmentContextBuilder,
    ) {
    }

    #[Route('', name: 'index', requirements: ['workspaceId' => '\d+'], methods: ['GET'])]
    public function index(int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);

        return $this->render('@NowoApiStudioBundle/service/index.html.twig', [
            'workspace' => $workspace,
            'services'  => $workspace->getServices(),
        ]);
    }

    #[Route('/new', name: 'new', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = new ApiService('New service', 'new_service');
        $workspace->addService($service);

        $form = $this->createForm(ApiServiceFormType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($service);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.created');

            return $this->redirectToRoute('nowo_api_studio_service_show', [
                'workspaceId' => $workspaceId,
                'id'          => $service->getId(),
            ]);
        }

        return $this->render('@NowoApiStudioBundle/service/form.html.twig', [
            'form'      => $form,
            'title'     => 'page.new_service',
            'workspace' => $workspace,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($id, $workspace);

        return $this->render('@NowoApiStudioBundle/service/show.html.twig', [
            'workspace'       => $workspace,
            'service'         => $service,
            'variableCatalog' => $this->environmentContextBuilder->buildVariableCatalog($workspace),
            'environments'    => $workspace->getEnvironments(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($id, $workspace);
        $form      = $this->createForm(ApiServiceFormType::class, $service);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.updated');

            return $this->redirectToRoute('nowo_api_studio_service_show', [
                'workspaceId' => $workspaceId,
                'id'          => $id,
            ]);
        }

        return $this->render('@NowoApiStudioBundle/service/form.html.twig', [
            'form'      => $form,
            'title'     => 'page.edit_service',
            'workspace' => $workspace,
            'service'   => $service,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($id, $workspace);

        if (!$this->isCsrfTokenValid('delete' . $service->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $confirmation = trim((string) $request->request->get('confirmation'));
        if ($confirmation !== $service->getName()) {
            $this->addFlash('error', 'service.delete_confirmation_mismatch');

            return $this->redirect($this->resolveDeleteRedirect($request, $workspaceId));
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.deleted');

        return $this->redirect($this->resolveDeleteRedirect($request, $workspaceId));
    }

    private function resolveDeleteRedirect(Request $request, int $workspaceId): string
    {
        $redirect = (string) $request->request->get('redirect');
        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            return $redirect;
        }

        return $this->generateUrl('nowo_api_studio_workspace_show', ['id' => $workspaceId]);
    }

    private function requireWorkspace(int $id): ApiWorkspace
    {
        $workspace = $this->workspaceRepository->find($id);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return $workspace;
    }

    private function requireService(int $id, ApiWorkspace $workspace): ApiService
    {
        $service = $this->repository->find($id);
        if (!$service instanceof ApiService || $service->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Service not found.');
        }

        return $service;
    }
}
