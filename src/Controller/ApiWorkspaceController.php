<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Form\ApiWorkspaceFormType;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/workspaces', name: 'nowo_api_studio_workspace_')]
final class ApiWorkspaceController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@NowoApiStudioBundle/workspace/index.html.twig', [
            'workspaces' => $this->repository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $workspace = new ApiWorkspace('New workspace', 'new_workspace');
        $form      = $this->createForm(ApiWorkspaceFormType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($workspace);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.created');

            return $this->redirectToRoute('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]);
        }

        return $this->render('@NowoApiStudioBundle/workspace/form.html.twig', [
            'form'  => $form,
            'title' => 'page.new_workspace',
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        return $this->render('@NowoApiStudioBundle/workspace/show.html.twig', [
            'workspace' => $this->requireWorkspace($id),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $workspace = $this->requireWorkspace($id);
        $form      = $this->createForm(ApiWorkspaceFormType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.updated');

            return $this->redirectToRoute('nowo_api_studio_workspace_show', ['id' => $id]);
        }

        return $this->render('@NowoApiStudioBundle/workspace/form.html.twig', [
            'form'      => $form,
            'title'     => 'page.edit_workspace',
            'workspace' => $workspace,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $workspace = $this->requireWorkspace($id);

        if (!$this->isCsrfTokenValid('delete' . $workspace->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($workspace);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.deleted');

        return $this->redirectToRoute('nowo_api_studio_workspace_index');
    }

    private function requireWorkspace(int $id): ApiWorkspace
    {
        $workspace = $this->repository->find($id);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return $workspace;
    }
}
