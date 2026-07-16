<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiRequestExampleRepository;
use Nowo\ApiStudioBundle\Repository\ApiResponseExampleRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function is_scalar;
use function is_string;

#[Route(path: '/workspaces/{workspaceId}/services/{serviceId}/endpoints/{endpointId}/examples', name: 'nowo_api_studio_endpoint_examples_')]
final class EndpointExamplesController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly ApiRequestExampleRepository $requestExampleRepository,
        private readonly ApiResponseExampleRepository $responseExampleRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/request', name: 'save_request', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'endpointId' => '\d+'], methods: ['POST'])]
    public function saveRequest(Request $request, int $workspaceId, int $serviceId, int $endpointId): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_examples', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->requireEndpoint($workspaceId, $serviceId, $endpointId);
        $payload  = $this->decodePayload($request);
        $name     = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Name is required.'], Response::HTTP_BAD_REQUEST);
        }

        $example = new ApiRequestExample($name);
        $example->setRequestBody(isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : null);
        $example->setHeaders($this->stringMap($payload['headers'] ?? []));
        $example->setQueryParams($this->stringMap($payload['query_params'] ?? []));
        $example->setSortOrder($endpoint->getRequestExamples()->count());
        $endpoint->addRequestExample($example);

        $this->entityManager->persist($example);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeRequestExample($example));
    }

    #[Route('/response', name: 'save_response', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'endpointId' => '\d+'], methods: ['POST'])]
    public function saveResponse(Request $request, int $workspaceId, int $serviceId, int $endpointId): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_examples', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->requireEndpoint($workspaceId, $serviceId, $endpointId);
        $payload  = $this->decodePayload($request);
        $name     = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Name is required.'], Response::HTTP_BAD_REQUEST);
        }

        $statusCode = isset($payload['status_code']) && is_numeric($payload['status_code'])
            ? (int) $payload['status_code']
            : 200;

        $example = new ApiResponseExample($name, $statusCode);
        $example->setResponseBody(isset($payload['response_body']) && is_string($payload['response_body']) ? $payload['response_body'] : null);
        $example->setHeaders($this->stringMap($payload['response_headers'] ?? []));
        $example->setSortOrder($endpoint->getResponseExamples()->count());
        $endpoint->addResponseExample($example);

        $this->entityManager->persist($example);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeResponseExample($example));
    }

    #[Route('/request/{exampleId}/delete', name: 'delete_request', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'endpointId' => '\d+', 'exampleId' => '\d+'], methods: ['POST'])]
    public function deleteRequest(Request $request, int $workspaceId, int $serviceId, int $endpointId, int $exampleId): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_examples', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->requireEndpoint($workspaceId, $serviceId, $endpointId);
        $example  = $this->requestExampleRepository->find($exampleId);
        if (!$example instanceof ApiRequestExample || $example->getEndpoint()?->getId() !== $endpoint->getId()) {
            return new JsonResponse(['error' => 'Example not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($example);
        $this->entityManager->flush();

        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/response/{exampleId}/delete', name: 'delete_response', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'endpointId' => '\d+', 'exampleId' => '\d+'], methods: ['POST'])]
    public function deleteResponse(Request $request, int $workspaceId, int $serviceId, int $endpointId, int $exampleId): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_examples', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->requireEndpoint($workspaceId, $serviceId, $endpointId);
        $example  = $this->responseExampleRepository->find($exampleId);
        if (!$example instanceof ApiResponseExample || $example->getEndpoint()?->getId() !== $endpoint->getId()) {
            return new JsonResponse(['error' => 'Example not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($example);
        $this->entityManager->flush();

        return new JsonResponse(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function decodePayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && $key !== '') {
                $map[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function serializeRequestExample(ApiRequestExample $example): array
    {
        return [
            'id'           => $example->getId(),
            'name'         => $example->getName(),
            'body'         => $example->getRequestBody(),
            'headers'      => $example->getHeaders(),
            'query_params' => $example->getQueryParams(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeResponseExample(ApiResponseExample $example): array
    {
        return [
            'id'               => $example->getId(),
            'name'             => $example->getName(),
            'status_code'      => $example->getStatusCode(),
            'response_body'    => $example->getResponseBody(),
            'response_headers' => $example->getHeaders(),
        ];
    }

    private function requireEndpoint(int $workspaceId, int $serviceId, int $endpointId): ApiEndpoint
    {
        $workspace = $this->workspaceRepository->find($workspaceId);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        $service = $this->serviceRepository->find($serviceId);
        if (!$service instanceof ApiService || $service->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Service not found.');
        }

        $endpoint = $this->endpointRepository->find($endpointId);
        if (!$endpoint instanceof ApiEndpoint || $endpoint->getService()?->getId() !== $service->getId()) {
            throw $this->createNotFoundException('Endpoint not found.');
        }

        return $endpoint;
    }
}
