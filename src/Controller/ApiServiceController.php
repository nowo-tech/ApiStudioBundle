<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Form\ApiServiceFormType;
use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/workspaces/{workspaceId}/services', name: 'nowo_api_studio_service_')]
final class ApiServiceController extends AbstractController
{
    use ApiStudioPageTrait;
    use AuthorizesTrait;
    use CsrfOnlyFormValidationTrait;
    use RowVersionEditTrait;
    use SlugConflictTrait;

    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EnvironmentContextBuilder $environmentContextBuilder,
        private readonly ?ApiFolderRepository $folderRepository = null,
        private readonly ?FolderAccess $folderAccess = null,
        private readonly bool $foldersEnabled = false,
        private readonly ?RemoteDialogResponder $remoteDialog = null,
    ) {
    }

    #[Route('', name: 'index', requirements: ['workspaceId' => '\d+'], methods: ['GET'])]
    public function index(int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);

        return $this->renderPage('@NowoApiStudioBundle/service/index.html.twig', [
            'page' => $this->pages()->serviceIndex($workspace, $workspace->getServices()),
        ]);
    }

    #[Route('/new', name: 'new', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId, ApiStudioAction::WorkspaceEdit);
        $service   = new ApiService('New service', 'new_service');
        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $workspace->addService($service);

        $form = $this->createForm(ApiServiceFormType::class, $service, ['scripts' => $this->authorization()->can(ApiStudioAction::WorkspaceEditScripts, $workspace)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($service);
            if ($this->flushUnlessSlugTaken($form)) {
                $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_SERVICE, $service->getId(), $workspace->getId(), ApiStudioResourceChangedEvent::ACTION_CREATED);
                $this->flash('success', 'flash.created');

                return $this->redirectToRoute('nowo_api_studio_service_show', [
                    'workspaceId' => $workspaceId,
                    'id'          => $service->getId(),
                ]);
            }
        }

        return $this->renderPage('@NowoApiStudioBundle/service/form.html.twig', [
            'page' => $this->pages()->serviceForm($workspace, null, $form->createView()),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'show', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($id, $workspace);

        return $this->renderPage('@NowoApiStudioBundle/service/show.html.twig', [
            'page'            => $this->pages()->serviceShow($workspace, $service),
            'workspace'       => $workspace,
            'service'         => $service,
            'variableCatalog' => $this->environmentContextBuilder->buildVariableCatalog($workspace),
            'environments'    => $workspace->getEnvironments(),
            'folders'         => $this->folderList($service),
        ]);
    }

    /**
     * 1.14: the service's folders in tree order with their depth (hidden folders left out); null when folders are off.
     *
     * @return list<array{folder: ApiFolder, depth: int}>|null
     */
    private function folderList(ApiService $service): ?array
    {
        if (!$this->foldersEnabled || !$this->folderRepository instanceof ApiFolderRepository) {
            return null;
        }

        $children = [];
        foreach ($this->folderRepository->findByService($service) as $folder) {
            $children[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        $list = [];
        $walk = function (int $parent, int $depth, array $seen) use (&$walk, &$list, $children): void {
            foreach ($children[$parent] ?? [] as $folder) {
                $id = (int) $folder->getId();
                if (isset($seen[$id]) || ($this->folderAccess instanceof FolderAccess && !$this->folderAccess->can(ApiStudioAction::WorkspaceView, $folder))) {
                    continue;
                }
                $list[] = ['folder' => $folder, 'depth' => $depth];
                $walk($id, $depth + 1, $seen + [$id => true]);
            }
        };
        $walk(0, 1, []);

        return $list;
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId, ApiStudioAction::WorkspaceEdit);
        $service   = $this->requireService($id, $workspace);
        $options   = ['scripts' => $this->authorization()->can(ApiStudioAction::WorkspaceEditScripts, $workspace)];
        $form      = $this->createForm(ApiServiceFormType::class, $service, $options + [RowVersionField::OPTION => $service->getRowVersion()]);
        $form->handleRequest($request);
        $status  = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $headers = [];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($this->saveEdit($form, $service)) {
                    $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_SERVICE, $id, $workspaceId, ApiStudioResourceChangedEvent::ACTION_UPDATED);
                    $this->flash('success', 'flash.updated');

                    return $this->redirectToRoute('nowo_api_studio_service_show', [
                        'workspaceId' => $workspaceId,
                        'id'          => $id,
                    ]);
                }
            } catch (StaleEditException) {
                // 1.20: saved by someone else after this form was rendered: the form again, with the stored data.
                [$status, $headers] = $this->answerStaleEdit($request, $service);
                $form               = $this->createForm(ApiServiceFormType::class, $service, $options + [RowVersionField::OPTION => $service->getRowVersion()]);
                $this->flash('error', StaleEditException::MESSAGE_KEY);
            }
        }

        return $this->renderPage('@NowoApiStudioBundle/service/form.html.twig', [
            'page' => $this->pages()->serviceForm(
                $workspace,
                $service,
                $form->createView(),
                $this->csrfOnlyForm(
                    $this->generateUrl('nowo_api_studio_service_delete', ['workspaceId' => $workspaceId, 'id' => $id]),
                    'delete' . $id,
                    // The UiKit confirm dialog asks for the name (type-to-confirm); the controller checks it again.
                    ['confirmation' => $service->getName()],
                ),
            ),
        ], new Response(status: $status, headers: $headers));
    }

    /**
     * GET: the delete confirmation, loaded when the dialog opens (owner rule 2026-11-23, every dialog loads its content on
     * demand): the fragment for `X-Nowo-Dialog: 1` (type the service name to confirm), the service page otherwise. POST: the
     * delete; from the dialog it answers 204 + `X-Nowo-Redirect`, a wrong confirmation 422 in place.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Request $request, int $workspaceId, int $id): Response
    {
        $workspace = $this->requireWorkspace($workspaceId, ApiStudioAction::WorkspaceEdit);
        $service   = $this->requireService($id, $workspace);
        $responder = $this->remoteDialog ?? new RemoteDialogResponder();

        if ($request->isMethod('GET')) {
            if (!$responder->isDialog($request)) {
                return $this->redirectToRoute('nowo_api_studio_service_show', ['workspaceId' => $workspaceId, 'id' => $id]);
            }

            return $this->deleteDialog($workspace, $service, $this->resolveDeleteRedirect($request, $workspaceId, true));
        }

        // REQ-TWIG-005: the delete button posts a CsrfOnlyType form (flat `_token`).
        if (!$this->isCsrfOnlyFormValid($request, 'delete' . $service->getId())) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $confirmation = trim((string) $request->request->get('confirmation'));
        if ($confirmation !== $service->getName()) {
            if ($responder->isDialog($request)) {
                return $this->deleteDialog($workspace, $service, $this->resolveDeleteRedirect($request, $workspaceId), true);
            }
            $this->flash('error', 'service.delete_confirmation_mismatch');

            return $this->redirect($this->resolveDeleteRedirect($request, $workspaceId));
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();
        $this->resourceChanged(ApiStudioResourceChangedEvent::TYPE_SERVICE, $id, $workspaceId, ApiStudioResourceChangedEvent::ACTION_DELETED);
        $this->flash('success', 'flash.deleted');

        $target = $this->resolveDeleteRedirect($request, $workspaceId);

        return $responder->isDialog($request) ? $responder->redirect($target, request: $request) : $this->redirect($target);
    }

    private function deleteDialog(ApiWorkspace $workspace, ApiService $service, string $redirect, bool $mismatch = false): Response
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = $this->container->get('form.factory');
        $form        = $formFactory->createNamedBuilder('', CsrfOnlyType::class, null, [
            'csrf_token_id' => 'delete' . $service->getId(),
            'action'        => $this->generateUrl('nowo_api_studio_service_delete', ['workspaceId' => $workspace->getId(), 'id' => $service->getId()]),
        ])
            ->add('redirect', HiddenType::class, ['data' => $redirect, 'mapped' => false])
            ->add('confirmation', TextType::class, ['label' => false, 'mapped' => false, 'attr' => ['class' => 'as-input', 'autocomplete' => 'off', 'spellcheck' => 'false', 'autofocus' => 'autofocus', 'data-as-delete-expected' => $service->getName()]])
            ->getForm();

        $response = $this->render('@NowoApiStudioBundle/service/_delete_dialog.html.twig', [
            'form'     => $form->createView(),
            'service'  => $service,
            'mismatch' => $mismatch,
        ], new Response(status: $mismatch ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        return ($this->remoteDialog ?? new RemoteDialogResponder())->fragment($response);
    }

    private function resolveDeleteRedirect(Request $request, int $workspaceId, bool $fromQuery = false): string
    {
        // Local paths only: browsers treat "//host" and "/\host" as protocol-relative (open redirect); no control chars.
        $redirect = (string) ($fromQuery ? $request->query->get('redirect') : $request->request->get('redirect'));
        if (preg_match('#^/(?![/\\\\])#', $redirect) === 1 && preg_match('/[\\x00-\\x1F\\x7F]/', $redirect) === 0) {
            return $redirect;
        }

        return $this->generateUrl('nowo_api_studio_workspace_show', ['id' => $workspaceId]);
    }

    private function requireWorkspace(int $id, ApiStudioAction $action = ApiStudioAction::WorkspaceView): ApiWorkspace
    {
        $workspace = $this->authorization()->requireWorkspace($this->workspaceRepository->find($id), $action);

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
