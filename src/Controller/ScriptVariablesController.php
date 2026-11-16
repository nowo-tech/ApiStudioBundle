<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Script\ScriptVariableStore;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Twig\ApiStudioExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function json_decode;
use function strlen;

/**
 * Persists `pm.globals` (the endpoint's workspace) and `pm.collectionVariables` (the endpoint's service) changed by
 * sandboxed scripts (spec 003, 1.15; 404 unless `scripts.mode: sandbox`). Called by the console only when the user
 * asked to persist variables: same CSRF token and `workspace.edit` as the environment variable sync.
 */
#[Route(path: '/scripts/variables', name: 'nowo_api_studio_')]
final class ScriptVariablesController extends AbstractController
{
    use AuthorizesTrait;

    private const MAX_PAYLOAD = 8_000_000;

    public function __construct(
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly ScriptVariableStore $store,
        private readonly ?FolderAccess $folderAccess = null,
        private readonly string $scriptsMode = ApiStudioExtension::SCRIPTS_INLINE,
    ) {
    }

    #[Route('/{id}', name: 'script_variables', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $id): JsonResponse
    {
        if ($this->scriptsMode !== ApiStudioExtension::SCRIPTS_SANDBOX) {
            return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('api_studio_env_sync', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->endpointRepository->find($id);
        $service  = $endpoint instanceof ApiEndpoint ? $endpoint->getService() : null;
        try {
            $workspace = $this->authorization()->requireWorkspace($service?->getWorkspace(), ApiStudioAction::WorkspaceEdit);
            if ($endpoint instanceof ApiEndpoint) {
                $this->folderAccess?->requireEndpoint($endpoint, ApiStudioAction::WorkspaceEdit);
            }
        } catch (NotFoundHttpException) {
            return new JsonResponse(['error' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedHttpException) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $content    = $request->getContent();
        $payload    = strlen($content) <= self::MAX_PAYLOAD ? json_decode($content, true) : null;
        $globals    = is_array($payload) ? ($payload['globals'] ?? []) : null;
        $collection = is_array($payload) ? ($payload['collection'] ?? []) : null;
        if (!is_array($globals) || !is_array($collection) || $service === null) {
            return new JsonResponse(['error' => 'Invalid payload.'], Response::HTTP_BAD_REQUEST);
        }

        $workspaceId = (int) $workspace->getId();
        $serviceId   = (int) $service->getId();
        try {
            $this->store->validate($workspaceId, null, $globals);
            $this->store->validate($workspaceId, $serviceId, $collection);
            $written = $this->store->apply($workspaceId, null, $globals) + $this->store->apply($workspaceId, $serviceId, $collection);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'success'    => true,
            'written'    => $written,
            'globals'    => (object) $this->store->values($workspaceId, null),
            'collection' => (object) $this->store->values($workspaceId, $serviceId),
        ]);
    }
}
