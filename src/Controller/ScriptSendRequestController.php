<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Script\ScriptRequestSender;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Twig\ApiStudioExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\SubscribedService;

use function is_array;
use function json_decode;
use function strlen;

/**
 * `pm.sendRequest` of sandboxed scripts (spec 003, 1.15; 404 unless `scripts.mode: sandbox`): an internal UI JSON
 * endpoint of the request console (CSRF header of the console, `workspace.run` on the endpoint's workspace and
 * folder). The request is sent by {@see ScriptRequestSender}: literal, guarded, no secret resolved.
 */
#[Route(path: '/scripts/send-request', name: 'nowo_api_studio_')]
final class ScriptSendRequestController extends AbstractController
{
    use AuthorizesTrait;

    /**
     * The request's authorization and 403 / 404 policy (1.16.0: without them the controller fell back to the
     * tenant-less, everything-granted default).
     *
     * @return array<string|SubscribedService>
     */
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            ApiStudioAuthorization::class => '?' . ApiStudioAuthorization::class,
            ApiStudioAccessPolicy::class  => '?' . ApiStudioAccessPolicy::class,
        ];
    }

    private const MAX_PAYLOAD = 1_200_000;

    public function __construct(
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly ScriptRequestSender $sender,
        private readonly ?FolderAccess $folderAccess = null,
        private readonly string $scriptsMode = ApiStudioExtension::SCRIPTS_INLINE,
    ) {
    }

    #[Route('/{id}', name: 'script_send_request', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(Request $request, int $id): JsonResponse
    {
        if ($this->scriptsMode !== ApiStudioExtension::SCRIPTS_SANDBOX) {
            return new JsonResponse(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('api_studio_execute', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->endpointRepository->find($id);
        if (!$endpoint instanceof ApiEndpoint) {
            return new JsonResponse(['error' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        }
        try {
            $this->authorization()->requireWorkspace($endpoint->getService()?->getWorkspace(), ApiStudioAction::WorkspaceRun);
            $this->folderAccess?->requireEndpoint($endpoint, ApiStudioAction::WorkspaceRun);
        } catch (NotFoundHttpException) {
            return new JsonResponse(['error' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedHttpException) {
            // 1.16: 403, or under access_denied_response: 404 exactly the not-found answer above.
            return $this->accessPolicy()->deniedJson('Endpoint not found.');
        }

        $content = $request->getContent();
        $payload = strlen($content) <= self::MAX_PAYLOAD ? json_decode($content, true) : null;
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'pm.sendRequest: invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['response' => $this->sender->send($payload)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
