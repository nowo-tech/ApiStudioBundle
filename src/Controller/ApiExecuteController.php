<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Model\ApiExecutionResult;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Service\HistorySanitizer;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\SubscribedService;

use function array_intersect;
use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function mb_substr;
use function parse_url;
use function strtolower;

use const PHP_URL_HOST;

#[Route(path: '/execute', name: 'nowo_api_studio_')]
final class ApiExecuteController extends AbstractController
{
    use AuthorizesTrait;
    use StudioEventsTrait;

    /**
     * @return array<string|SubscribedService>
     */
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            ApiStudioAuthorization::class => '?' . ApiStudioAuthorization::class,
            ApiStudioHistoryScope::class  => '?' . ApiStudioHistoryScope::class,
            'logger'                      => '?' . LoggerInterface::class,
            'event_dispatcher'            => '?' . EventDispatcherInterface::class,
        ];
    }

    public function __construct(
        private readonly ApiEndpointRepository $endpointRepository,
        private readonly ApiEnvironmentRepository $environmentRepository,
        private readonly RequestExecutor $requestExecutor,
        private readonly HistorySanitizer $historySanitizer,
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

        // The endpoint's workspace: outside the scope or not viewable → 404, viewable but not runnable → 403.
        try {
            $this->authorization()->requireWorkspace($endpoint->getService()?->getWorkspace(), ApiStudioAction::WorkspaceRun);
        } catch (NotFoundHttpException) {
            return new JsonResponse(['error' => 'Endpoint not found.'], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedHttpException) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $environment   = null;
        $environmentId = $payload['environment_id'] ?? null;
        if (is_int($environmentId) || (is_string($environmentId) && ctype_digit($environmentId))) {
            $environment = $this->environmentRepository->find((int) $environmentId);
            // Unknown, hidden by the tenant filter or of another workspace: the same 400 (ER-I3).
            if (!$environment instanceof ApiEnvironment || !self::belongsToEndpointWorkspace($environment, $endpoint)) {
                // Never resolve another workspace's variables/secrets into this endpoint's request.
                return new JsonResponse(['error' => 'Environment does not belong to the endpoint workspace.'], Response::HTTP_BAD_REQUEST);
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

        // The console receives secret variables as their own placeholder (EnvironmentContextBuilder::browserVariableMap()):
        // an override that is still that placeholder means "use the stored secret".
        if ($environment instanceof ApiEnvironment) {
            foreach ($environment->getVariables() as $variable) {
                $key      = $variable->getVariableKey();
                $override = $variableOverrides[$key] ?? null;
                if ($variable->isSecret() && $override !== null && VariableSyntax::isPlaceholder($override) && VariableSyntax::normalizeKey($override) === $key) {
                    unset($variableOverrides[$key]);
                }
            }
        }

        // ER-M1: without workspace.edit, a runner may not change the variables that decide where the request goes or
        // which credential it carries (that would send the stored secrets to a host of their choice). Values the
        // console sends unchanged (the stored ones) are fine.
        $service = $endpoint->getService();
        if ($service !== null && !$this->authorization()->can(ApiStudioAction::WorkspaceEdit, $service->getWorkspace())) {
            $refused = $this->refusedOverrides($endpoint, $service, $environment, $variableOverrides);
            if ($refused !== null) {
                $this->logRefusedOverride($endpoint, $environment, $refused);

                return new JsonResponse([
                    'error'     => 'override_not_allowed',
                    'message'   => 'Changing these variables needs the right to edit the workspace: they decide where the request goes or which credential it carries.',
                    'variables' => $refused,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
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

        [$authNames, $secretValues] = self::historyRedactionHints($endpoint, $environment, $variableOverrides);

        $history = new ApiRequestHistory();
        $history->setEndpoint($endpoint);
        if ($this->historyScope()->isPerUser()) {
            $history->setExecutedBy($this->historyScope()->actorId($this->authorization()->user()));
        }
        $history->setEnvironment($environment);
        // request_url is VARCHAR(2048): truncate instead of losing the history row (and the response) to a DB error.
        $history->setRequestUrl(mb_substr($this->historySanitizer->sanitizeUrl($result->requestUrl, $authNames, $secretValues), 0, 2048));
        $history->setRequestMethod($result->requestMethod);
        $history->setRequestHeaders($this->historySanitizer->sanitizeHeaders($result->requestHeaders, $authNames, $secretValues));
        $history->setRequestBody($this->historySanitizer->sanitizeBody($result->requestBody, $secretValues));
        $history->setResponseStatus($result->responseStatus);
        // Upstreams may echo credentials (httpbin, error pages, token responses): mask the secret values there too.
        $history->setResponseHeaders($this->historySanitizer->sanitizeHeaders($result->responseHeaders, [], $secretValues));
        $history->setResponseBody($this->historySanitizer->sanitizeBody($result->responseBody, $secretValues));
        $history->setDurationMs($result->durationMs);
        $history->setSuccess($result->success);
        $history->setErrorMessage($result->errorMessage);

        $this->entityManager->persist($history);
        $this->entityManager->flush();
        $historyId = $history->getId();
        $this->entityManager->detach($history);

        // 1.12: ids and scalars only (no path, query, headers, bodies or variable values: they may carry secrets).
        $host = parse_url($result->requestUrl, PHP_URL_HOST);
        $this->dispatchEvent(new ApiRequestExecutedEvent(
            endpointId: (int) $endpoint->getId(),
            serviceId: $service?->getId(),
            workspaceId: $service?->getWorkspace()?->getId(),
            environmentId: $environment?->getId(),
            historyId: $historyId,
            method: $result->requestMethod,
            host: is_string($host) && $host !== '' ? strtolower($host) : null,
            statusCode: $result->responseStatus,
            durationMs: $result->durationMs,
            success: $result->success,
            actorId: $this->currentActorId(),
        ));

        // The browser never gets secret values back either (resolved Authorization header, echoed tokens).
        $data = $result->toArray();
        if ($secretValues !== []) {
            $data['request_url']      = $this->historySanitizer->sanitizeUrl($result->requestUrl, [], $secretValues);
            $data['request_headers']  = $this->historySanitizer->sanitizeHeaders($result->requestHeaders, [], $secretValues);
            $data['request_body']     = $this->historySanitizer->sanitizeBody($result->requestBody, $secretValues);
            $data['response_headers'] = $this->historySanitizer->sanitizeHeaders($result->responseHeaders, [], $secretValues);
            $data['response_body']    = $this->historySanitizer->sanitizeBody($result->responseBody, $secretValues);
        }

        // Fail closed (secret that cannot be decrypted): nothing was sent; the console shows the error message.
        $status = $result->errorCode === ApiExecutionResult::ERROR_SECRET_UNREADABLE ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return new JsonResponse($data, $status);
    }

    /**
     * The protected variables a runner without `workspace.edit` changed (null: nothing refused).
     *
     * @param array<string, string> $overrides
     *
     * @return list<string>|null
     */
    private function refusedOverrides(ApiEndpoint $endpoint, ApiService $service, ?ApiEnvironment $environment, array $overrides): ?array
    {
        $stored  = $environment?->getVariableMap() ?? [];
        $changed = [];
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $stored) || $stored[$key] !== $value) {
                $changed[] = (string) $key;
            }
        }

        $refused = array_values(array_intersect($changed, RequestExecutor::protectedVariables($endpoint, $service, $environment)));

        return $refused === [] ? null : $refused;
    }

    /**
     * @param list<string> $variables
     */
    private function logRefusedOverride(ApiEndpoint $endpoint, ?ApiEnvironment $environment, array $variables): void
    {
        if (!$this->container->has('logger')) {
            return;
        }

        /** @var LoggerInterface $logger */
        $logger = $this->container->get('logger');
        $logger->warning('Api Studio request refused: a runner without workspace.edit changed a protected variable or the request origin', [
            'bundle'         => 'api-studio',
            'action'         => 'execute_override_refused',
            'endpoint_id'    => $endpoint->getId(),
            'environment_id' => $environment?->getId(),
            'variable_count' => count($variables),
        ]);
    }

    /**
     * Header/query names carrying the service credential and the values of secret environment variables,
     * so they can be redacted from the persisted history (URL, headers and body). A value sent in the payload
     * for a secret variable is treated as secret too.
     *
     * @param array<string, string> $variableOverrides
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function historyRedactionHints(ApiEndpoint $endpoint, ?ApiEnvironment $environment, array $variableOverrides = []): array
    {
        $names   = [];
        $service = $endpoint->getService();
        if ($service !== null && $service->getAuthType() === AuthType::ApiKey) {
            // Same default as RequestExecutor::applyApiKeyAuth(); the name is also redacted if it appears in the query.
            $names[] = (string) ($service->getAuthConfig()['header'] ?? 'X-Api-Key');
        }

        $secrets = [];
        if ($environment instanceof ApiEnvironment) {
            foreach ($environment->getVariables() as $variable) {
                if (!$variable->isSecret()) {
                    continue;
                }
                if ($variable->getValue() !== '') {
                    $secrets[] = $variable->getValue();
                }
                $override = $variableOverrides[$variable->getVariableKey()] ?? '';
                if ($override !== '') {
                    $secrets[] = $override;
                }
            }
        }

        return [$names, $secrets];
    }

    private static function belongsToEndpointWorkspace(ApiEnvironment $environment, ApiEndpoint $endpoint): bool
    {
        $endpointWorkspace = $endpoint->getService()?->getWorkspace();

        return $endpointWorkspace instanceof ApiWorkspace && $environment->getWorkspace() === $endpointWorkspace;
    }
}
