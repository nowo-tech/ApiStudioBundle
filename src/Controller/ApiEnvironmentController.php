<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Form\ApiEnvironmentFormType;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/workspaces/{workspaceId}/environments', name: 'nowo_api_studio_environment_')]
final class ApiEnvironmentController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiEnvironmentRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', requirements: ['workspaceId' => '\d+'], methods: ['GET'])]
    public function index(int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);

        return $this->render('@NowoApiStudioBundle/environment/index.html.twig', [
            'workspace'    => $workspace,
            'environments' => $workspace->getEnvironments(),
        ]);
    }

    #[Route('/new', name: 'new', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $workspaceId): Response
    {
        $workspace   = $this->requireWorkspace($workspaceId);
        $environment = new ApiEnvironment('New environment', 'new_env');
        $workspace->addEnvironment($environment);

        $form = $this->createForm(ApiEnvironmentFormType::class, $environment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($environment->isDefault()) {
                $this->clearDefaultFlag($workspace, $environment);
            }

            $this->entityManager->persist($environment);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.created');

            return $this->redirectToRoute('nowo_api_studio_environment_show', [
                'workspaceId' => $workspaceId,
                'id'          => $environment->getId(),
            ]);
        }

        return $this->render('@NowoApiStudioBundle/environment/form.html.twig', [
            'form'      => $form,
            'title'     => 'page.new_environment',
            'workspace' => $workspace,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);

        return $this->render('@NowoApiStudioBundle/environment/show.html.twig', [
            'workspace'   => $workspace,
            'environment' => $this->requireEnvironment($id, $workspace),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $workspaceId, int $id): Response
    {
        $workspace   = $this->requireWorkspace($workspaceId);
        $environment = $this->requireEnvironment($id, $workspace);
        $form        = $this->createForm(ApiEnvironmentFormType::class, $environment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($environment->isDefault()) {
                $this->clearDefaultFlag($workspace, $environment);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'flash.updated');

            return $this->redirectToRoute('nowo_api_studio_environment_show', [
                'workspaceId' => $workspaceId,
                'id'          => $id,
            ]);
        }

        return $this->render('@NowoApiStudioBundle/environment/form.html.twig', [
            'form'        => $form,
            'title'       => 'page.edit_environment',
            'workspace'   => $workspace,
            'environment' => $environment,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $workspaceId, int $id): Response
    {
        $workspace   = $this->requireWorkspace($workspaceId);
        $environment = $this->requireEnvironment($id, $workspace);

        if (!$this->isCsrfTokenValid('delete' . $environment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($environment);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.deleted');

        return $this->redirectToRoute('nowo_api_studio_environment_index', ['workspaceId' => $workspaceId]);
    }

    private function requireWorkspace(int $id): ApiWorkspace
    {
        $workspace = $this->workspaceRepository->find($id);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return $workspace;
    }

    private function requireEnvironment(int $id, ApiWorkspace $workspace): ApiEnvironment
    {
        $environment = $this->repository->find($id);
        if (!$environment instanceof ApiEnvironment || $environment->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Environment not found.');
        }

        return $environment;
    }

    private function clearDefaultFlag(ApiWorkspace $workspace, ApiEnvironment $current): void
    {
        foreach ($workspace->getEnvironments() as $environment) {
            if ($environment !== $current) {
                $environment->setIsDefault(false);
            }
        }
    }
}
