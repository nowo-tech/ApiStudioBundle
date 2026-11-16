<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Form\ApiFolderFormType;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Service\FolderTree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function is_array;
use function is_int;
use function json_decode;
use function strcmp;
use function usort;

/**
 * Folders of a service tree (1.14, `nowo_api_studio.folders.enabled`; 404 otherwise): create, edit, delete (children
 * and endpoints move up) and the drag & drop moves of the API tree.
 */
#[Route(path: '/workspaces/{workspaceId}/services/{serviceId}/folders', name: 'nowo_api_studio_folder_')]
final class ApiFolderController extends AbstractController
{
    use ApiStudioPageTrait;
    use AuthorizesTrait;
    use CsrfOnlyFormValidationTrait;

    /** CSRF token id of the tree moves (header `X-CSRF-Token`). */
    public const MOVE_CSRF_ID = 'api_studio_tree_move';

    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiFolderRepository $folderRepository,
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderTree $folderTree,
        private readonly FolderAccess $folderAccess,
        private readonly bool $foldersEnabled = false,
    ) {
    }

    #[Route('/new', name: 'new', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $workspaceId, int $serviceId): Response
    {
        $context = $this->requireContext($workspaceId, $serviceId, ApiStudioAction::WorkspaceEdit);
        $folder  = new ApiFolder('');
        $folder->setService($context['service']);
        $parentId = $request->query->getInt('parent');
        if ($parentId > 0) {
            $folder->setParent($this->requireFolder($parentId, $context['service'], ApiStudioAction::WorkspaceEdit));
        }
        $folder->setSortOrder(FolderTree::nextSortOrder($this->folderRepository->children($context['service'], $folder->getParent())));

        $form = $this->createForm(ApiFolderFormType::class, $folder, ['parents' => $this->parentChoices($context['service'], null)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->placeable($form, $folder)) {
            $folder->setCreatedBy($this->currentActorId());
            $this->entityManager->persist($folder);
            $this->entityManager->flush();
            $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_FOLDER, $folder->getId(), $workspaceId, ApiStudioResourceChangedEvent::ACTION_CREATED);
            $this->flash('success', 'flash.created');

            return $this->redirectToRoute('nowo_api_studio_service_show', ['workspaceId' => $workspaceId, 'id' => $serviceId]);
        }

        return $this->renderPage('@NowoApiStudioBundle/folder/form.html.twig', [
            'page' => $this->pages()->folderForm($context['workspace'], $context['service'], null, $form->createView()),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        $context = $this->requireContext($workspaceId, $serviceId, ApiStudioAction::WorkspaceEdit);
        $folder  = $this->requireFolder($id, $context['service'], ApiStudioAction::WorkspaceEdit);
        $form    = $this->createForm(ApiFolderFormType::class, $folder, ['parents' => $this->parentChoices($context['service'], $folder)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->placeable($form, $folder)) {
            $this->entityManager->flush();
            $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_FOLDER, $id, $workspaceId, ApiStudioResourceChangedEvent::ACTION_UPDATED);
            $this->flash('success', 'flash.updated');

            return $this->redirectToRoute('nowo_api_studio_service_show', ['workspaceId' => $workspaceId, 'id' => $serviceId]);
        }

        return $this->renderPage('@NowoApiStudioBundle/folder/form.html.twig', [
            'page' => $this->pages()->folderForm(
                $context['workspace'],
                $context['service'],
                $folder,
                $form->createView(),
                $this->csrfOnlyForm(
                    $this->generateUrl('nowo_api_studio_folder_delete', ['workspaceId' => $workspaceId, 'serviceId' => $serviceId, 'id' => $id]),
                    'delete_folder' . $id,
                ),
            ),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        $context = $this->requireContext($workspaceId, $serviceId, ApiStudioAction::WorkspaceEdit);
        $folder  = $this->requireFolder($id, $context['service'], ApiStudioAction::WorkspaceEdit);

        if (!$this->isCsrfOnlyFormValid($request, 'delete_folder' . $id)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->folderTree->deleteFolder($folder);
        $this->entityManager->clear();
        $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_FOLDER, $id, $workspaceId, ApiStudioResourceChangedEvent::ACTION_DELETED);
        $this->flash('success', 'flash.deleted');

        return $this->redirectToRoute('nowo_api_studio_service_show', ['workspaceId' => $workspaceId, 'id' => $serviceId]);
    }

    /**
     * Drag & drop of the API tree: JSON `{type: folder|endpoint, id, parent: folder id|null, before: sibling id|null}`
     * with the `X-CSRF-Token` header. 422 `{error, code}` when the move breaks a rule (other service, cycle, depth).
     */
    #[Route('/move', name: 'tree_move', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['POST'])]
    public function move(Request $request, int $workspaceId, int $serviceId): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::MOVE_CSRF_ID, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $context = $this->requireContext($workspaceId, $serviceId, ApiStudioAction::WorkspaceEdit);
        $payload = json_decode($request->getContent(), true);
        $type    = is_array($payload) ? ($payload['type'] ?? null) : null;
        $id      = is_array($payload) ? ($payload['id'] ?? null) : null;
        if (!in_array($type, ['folder', 'endpoint'], true) || !is_int($id)) {
            return new JsonResponse(['error' => 'Invalid move.', 'code' => 'invalid'], Response::HTTP_BAD_REQUEST);
        }
        $parentId = $payload['parent'] ?? null;
        $beforeId = $payload['before'] ?? null;
        $parent   = is_int($parentId) ? $this->requireFolder($parentId, $context['service'], ApiStudioAction::WorkspaceEdit) : null;
        $before   = is_int($beforeId) ? $beforeId : null;

        try {
            if ($type === 'folder') {
                $folder = $this->requireFolder($id, $context['service'], ApiStudioAction::WorkspaceEdit);
                $this->folderTree->moveFolder($folder, $parent, $before);
            } else {
                $endpoint = $this->requireEndpoint($id, $context['service']);
                $this->folderTree->moveEndpoint($endpoint, $parent, $before);
            }
        } catch (DomainException $e) {
            return new JsonResponse(['error' => $this->translate($e->getMessage()), 'code' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->clear();
        $this->resourceChanged($type === 'folder' ? ApiStudioResourceChangedEvent::TYPE_FOLDER : ApiStudioResourceChangedEvent::TYPE_ENDPOINT, $id, $workspaceId, ApiStudioResourceChangedEvent::ACTION_MOVED);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * The folder's new place is valid (same service, no cycle, depth); otherwise the form gets the error.
     *
     * @param FormInterface<ApiFolder> $form
     */
    private function placeable(FormInterface $form, ApiFolder $folder): bool
    {
        try {
            $parent = $folder->getParent();
            if ($parent instanceof ApiFolder) {
                $this->folderAccess->requireFolder($parent, ApiStudioAction::WorkspaceEdit);
            }
            $this->folderTree->assertCanPlaceFolder($folder, $parent);
        } catch (DomainException $e) {
            $form->get('parent')->addError(new FormError($this->translate($e->getMessage())));

            return false;
        }

        return true;
    }

    /**
     * Folders $folder may move under: every folder of the service but itself and its descendants.
     *
     * @return list<ApiFolder>
     */
    private function parentChoices(ApiService $service, ?ApiFolder $folder): array
    {
        $choices = [];
        foreach ($this->folderRepository->findByService($service) as $candidate) {
            if ($folder instanceof ApiFolder && in_array($folder, $candidate->getPath(), true)) {
                continue;
            }
            if ($this->folderAccess->can(ApiStudioAction::WorkspaceEdit, $candidate)) {
                $choices[] = $candidate;
            }
        }
        usort($choices, static fn (ApiFolder $a, ApiFolder $b): int => strcmp(ApiFolderFormType::pathLabel($a), ApiFolderFormType::pathLabel($b)));

        return $choices;
    }

    /** @return array{workspace: ApiWorkspace, service: ApiService} */
    private function requireContext(int $workspaceId, int $serviceId, ApiStudioAction $action): array
    {
        if (!$this->foldersEnabled) {
            throw $this->createNotFoundException('Folders are not enabled.');
        }

        $workspace = $this->authorization()->requireWorkspace($this->workspaceRepository->find($workspaceId), $action);
        $service   = $this->serviceRepository->find($serviceId);
        if (!$service instanceof ApiService || $service->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Service not found.');
        }

        return ['workspace' => $workspace, 'service' => $service];
    }

    private function requireFolder(int $id, ApiService $service, ApiStudioAction $action): ApiFolder
    {
        $folder = $this->folderRepository->find($id);
        if (!$folder instanceof ApiFolder || $folder->getService()?->getId() !== $service->getId()) {
            throw $this->createNotFoundException('Folder not found.');
        }

        return $this->folderAccess->requireFolder($folder, $action);
    }

    private function requireEndpoint(int $id, ApiService $service): ApiEndpoint
    {
        $endpoint = $this->endpointRepository->find($id);
        if (!$endpoint instanceof ApiEndpoint || $endpoint->getService()?->getId() !== $service->getId()) {
            throw $this->createNotFoundException('Endpoint not found.');
        }

        return $this->folderAccess->requireEndpoint($endpoint, ApiStudioAction::WorkspaceEdit);
    }

    private function translate(string $key): string
    {
        if (!$this->container->has('translator')) {
            return $key;
        }
        /** @var TranslatorInterface $translator */
        $translator = $this->container->get('translator');

        return $translator->trans($key, ['%max%' => $this->folderTree->maxDepth()], 'NowoApiStudioBundle');
    }
}
