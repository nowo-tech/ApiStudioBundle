<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function is_string;

#[Route(path: '/workspaces/{workspaceId}/environments', name: 'nowo_api_studio_environment_')]
final class EnvironmentVariablesController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiEnvironmentRepository $environmentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/variables/sync', name: 'sync_variables', requirements: ['workspaceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function syncVariables(Request $request, int $workspaceId, int $id): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_env_sync', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $environment = $this->requireEnvironment($workspaceId, $id);
        $payload     = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['variables']) || !is_array($payload['variables'])) {
            return new JsonResponse(['error' => 'Invalid payload.'], Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        $created = 0;
        foreach ($payload['variables'] as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            try {
                $key = VariableSyntax::normalizeKey($key);
            } catch (InvalidArgumentException) {
                continue;
            }

            $variable = $this->findVariable($environment, $key);
            if (!$variable instanceof ApiEnvironmentVariable) {
                $variable = new ApiEnvironmentVariable($key, (string) $value);
                $environment->addVariable($variable);
                ++$created;
            } else {
                $variable->setValue((string) $value);
                ++$updated;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    private function requireEnvironment(int $workspaceId, int $id): ApiEnvironment
    {
        $workspace = $this->workspaceRepository->find($workspaceId);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        $environment = $this->environmentRepository->find($id);
        if (!$environment instanceof ApiEnvironment || $environment->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Environment not found.');
        }

        return $environment;
    }

    private function findVariable(ApiEnvironment $environment, string $key): ?ApiEnvironmentVariable
    {
        foreach ($environment->getVariables() as $variable) {
            if ($variable->getVariableKey() === $key) {
                return $variable;
            }
        }

        return null;
    }
}
