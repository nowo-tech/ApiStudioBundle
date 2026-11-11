<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceDeletedEvent;
use Nowo\ApiStudioBundle\Form\ApiWorkspaceFormType;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Service\WorkspaceEnvironmentInitializer;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(path: '/workspaces', name: 'nowo_api_studio_workspace_')]
final class ApiWorkspaceController extends AbstractController
{
    use ApiStudioPageTrait;
    use AuthorizesTrait;
    use CsrfOnlyFormValidationTrait;
    use SlugConflictTrait;

    public function __construct(
        private readonly ApiWorkspaceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceEnvironmentInitializer $environmentInitializer,
        private readonly int $listPageSize = 50,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // REQ-PERF-001: paginated list (ui.list_page_size rows per page).
        $scope      = $this->authorization()->scope();
        $total      = $this->repository->countInScope($scope);
        $totalPages = max(1, (int) ceil($total / $this->listPageSize));
        $page       = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        $workspaces = $this->repository->findInScope($scope, $this->listPageSize, ($page - 1) * $this->listPageSize);

        return $this->renderPage('@NowoApiStudioBundle/workspace/index.html.twig', [
            'page' => $this->pages()->workspaceIndex(
                $workspaces,
                new Pagination($page, $this->listPageSize, $total, $totalPages, 'nowo_api_studio_workspace_index'),
            ),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant    = $this->authorization()->tenant();
        $workspace = (new ApiWorkspace('New workspace', ''))->assignTenant($tenant);
        $form      = $this->createForm(ApiWorkspaceFormType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->environmentInitializer->initialize($workspace);
            $this->entityManager->persist($workspace);
            // The created event runs inside the transaction: a host listener writes atomically with the workspace.
            // The committed event (ids only) follows the commit, for outbox / integration listeners (1.12, rule 7).
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            try {
                if ($this->flushUnlessSlugTaken($form)) {
                    $actorId = $this->currentActorId();
                    $this->dispatchEvent(new ApiWorkspaceCreatedEvent($workspace, $tenant, $this->authorization()->user(), $actorId));
                    $connection->commit();
                    $this->dispatchEvent(new ApiWorkspaceCreatedCommittedEvent((int) $workspace->getId(), $workspace->getSlug(), $tenant, $actorId));
                    $this->flash('success', 'flash.created');

                    return $this->redirectToRoute('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]);
                }
                $connection->rollBack();
            } catch (Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }
        }

        return $this->renderPage('@NowoApiStudioBundle/workspace/form.html.twig', [
            'page' => $this->pages()->workspaceForm(null, $form->createView()),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $workspace = $this->requireWorkspace($id);

        return $this->renderPage('@NowoApiStudioBundle/workspace/show.html.twig', [
            'page'      => $this->pages()->workspaceShow($workspace),
            'workspace' => $workspace,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $workspace = $this->requireWorkspace($id, ApiStudioAction::WorkspaceManage);
        $form      = $this->createForm(ApiWorkspaceFormType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->flushUnlessSlugTaken($form)) {
                $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_WORKSPACE, $id, $id, ApiStudioResourceChangedEvent::ACTION_UPDATED);
                $this->flash('success', 'flash.updated');

                return $this->redirectToRoute('nowo_api_studio_workspace_show', ['id' => $id]);
            }
        }

        return $this->renderPage('@NowoApiStudioBundle/workspace/form.html.twig', [
            'page' => $this->pages()->workspaceForm(
                $workspace,
                $form->createView(),
                // Deleting a workspace removes its services, environments and secrets: type-to-confirm, checked again here.
                $this->csrfOnlyForm($this->generateUrl('nowo_api_studio_workspace_delete', ['id' => $id]), 'delete' . $id, ['confirmation' => $workspace->getName()]),
            ),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $workspace = $this->requireWorkspace($id, ApiStudioAction::WorkspaceManage);

        // REQ-TWIG-005: the delete button posts a CsrfOnlyType form (flat `_token`).
        if (!$this->isCsrfOnlyFormValid($request, 'delete' . $workspace->getId())) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (trim((string) $request->request->get('confirmation')) !== $workspace->getName()) {
            $this->flash('error', 'workspace.delete_confirmation_mismatch');

            return $this->redirectToRoute('nowo_api_studio_workspace_edit', ['id' => $id]);
        }

        $workspaceId = (int) $workspace->getId();
        $tenant      = $workspace->getTenant();
        $this->entityManager->remove($workspace);
        $this->entityManager->flush();
        $this->dispatchEvent(new ApiWorkspaceDeletedEvent($workspaceId, $tenant));
        $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_WORKSPACE, $workspaceId, $workspaceId, ApiStudioResourceChangedEvent::ACTION_DELETED);
        $this->flash('success', 'flash.deleted');

        return $this->redirectToRoute('nowo_api_studio_workspace_index');
    }

    private function requireWorkspace(int $id, ApiStudioAction $action = ApiStudioAction::WorkspaceView): ApiWorkspace
    {
        $workspace = $this->authorization()->requireWorkspace($this->repository->find($id), $action);

        return $workspace;
    }
}
