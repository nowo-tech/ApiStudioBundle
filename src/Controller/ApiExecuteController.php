<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;

#[Route(path: '/execute', name: 'nowo_api_studio_')]
final class ApiExecuteController extends AbstractController
{
    public function __construct(
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly ApiEnvironmentRepository $environmentRepository,
        private readonly RequestExecutor $requestExecutor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}', name: 'execute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function execute(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_execute', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->endpointRepository->find($id);
        if (!$endpoint instanceof ApiEndpoint) {
            return new JsonResponse(['error' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $environment   = null;
        $environmentId = $payload['environment_id'] ?? null;
        if (is_int($environmentId) || (is_string($environmentId) && ctype_digit($environmentId))) {
            $environment = $this->environmentRepository->find((int) $environmentId);
            if (!$environment instanceof ApiEnvironment) {
                $environment = null;
            }
        }

        $overrideBody    = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : null;
        $overrideHeaders = [];
        $replaceHeaders  = array_key_exists('headers', $payload);
        if ($replaceHeaders && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $overrideHeaders[$key] = is_scalar($value) ? (string) $value : '';
                }
            }
        }

        $overrideQueryParams = [];
        $replaceQueryParams  = array_key_exists('query_params', $payload);
        if ($replaceQueryParams && is_array($payload['query_params'])) {
            foreach ($payload['query_params'] as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $overrideQueryParams[$key] = is_scalar($value) ? (string) $value : '';
                }
            }
        }

        $variableOverrides = [];
        if (isset($payload['variables']) && is_array($payload['variables'])) {
            foreach ($payload['variables'] as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $variableOverrides[$key] = is_scalar($value) ? (string) $value : '';
                }
            }
        }

        $variableMap = null;
        if ($environment instanceof ApiEnvironment || $variableOverrides !== []) {
            $variableMap = array_merge($environment?->getVariableMap() ?? [], $variableOverrides);
        }

        $methodOverride = null;
        if (
            $endpoint->getService()?->getProtocol() === ApiProtocol::Rest
            && isset($payload['method'])
            && is_string($payload['method'])
        ) {
            $methodOverride = HttpMethod::tryFrom(strtoupper($payload['method']));
        }

        $result = $this->requestExecutor->execute(
            $endpoint,
            $environment,
            $overrideBody,
            $overrideHeaders,
            $overrideQueryParams,
            $variableMap,
            $replaceQueryParams,
            $replaceHeaders,
            $methodOverride,
        );

        $history = new ApiRequestHistory();
        $history->setEndpoint($endpoint);
        $history->setEnvironment($environment);
        $history->setRequestUrl($result->requestUrl);
        $history->setRequestMethod($result->requestMethod);
        $history->setRequestHeaders($result->requestHeaders);
        $history->setRequestBody($result->requestBody);
        $history->setResponseStatus($result->responseStatus);
        $history->setResponseHeaders($result->responseHeaders);
        $history->setResponseBody($result->responseBody);
        $history->setDurationMs($result->durationMs);
        $history->setSuccess($result->success);
        $history->setErrorMessage($result->errorMessage);

        $this->entityManager->persist($history);
        $this->entityManager->flush();

        return new JsonResponse($result->toArray());
    }
}
