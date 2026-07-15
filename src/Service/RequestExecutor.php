<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Model\ApiExecutionResult;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use SoapClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function extension_loaded;
use function in_array;
use function is_array;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_UNICODE;

/**
 * Executes REST, SOAP, and GraphQL requests with environment variable substitution.
 */
final class RequestExecutor
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly VariableResolver $variableResolver,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @param array<string, string> $overrideHeaders
     * @param array<string, string> $overrideQueryParams
     * @param array<string, string>|null $variables
     */
    public function execute(
        ApiEndpoint $endpoint,
        ?ApiEnvironment $environment = null,
        ?string $overrideBody = null,
        array $overrideHeaders = [],
        array $overrideQueryParams = [],
        ?array $variables = null,
        bool $replaceQueryParams = false,
        bool $replaceHeaders = false,
        ?HttpMethod $methodOverride = null,
    ): ApiExecutionResult {
        $service = $endpoint->getService();
        if (!$service instanceof ApiService) {
            return $this->failure('', 'GET', [], null, 'Endpoint has no service.');
        }

        $variableMap = $variables ?? ($environment?->getVariableMap() ?? []);

        return match ($service->getProtocol()) {
            ApiProtocol::Soap    => $this->executeSoap($endpoint, $service, $variableMap, $overrideBody, $overrideHeaders, $replaceHeaders),
            ApiProtocol::Graphql => $this->executeGraphql($endpoint, $service, $variableMap, $overrideBody, $overrideHeaders, $overrideQueryParams, $replaceQueryParams, $replaceHeaders),
            default              => $this->executeRest($endpoint, $service, $variableMap, $overrideBody, $overrideHeaders, $overrideQueryParams, $methodOverride, $replaceQueryParams, $replaceHeaders),
        };
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $overrideHeaders
     * @param array<string, string> $overrideQueryParams
     */
    private function executeRest(
        ApiEndpoint $endpoint,
        ApiService $service,
        array $variables,
        ?string $overrideBody,
        array $overrideHeaders,
        array $overrideQueryParams,
        ?HttpMethod $methodOverride = null,
        bool $replaceQueryParams = false,
        bool $replaceHeaders = false,
    ): ApiExecutionResult {
        $baseUrl = rtrim($this->variableResolver->resolve($service->getBaseUrl(), $variables), '/');
        $path    = $this->variableResolver->resolve($endpoint->getPath(), $variables);
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $querySource = $replaceQueryParams
            ? $overrideQueryParams
            : array_merge($endpoint->getQueryParams(), $overrideQueryParams);
        $queryParams = $this->variableResolver->resolveMap($querySource, $variables);
        $url         = $baseUrl . $path;
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = $this->buildHeaders($service, $endpoint, $variables, $overrideHeaders, $replaceHeaders);
        $body    = $overrideBody ?? $endpoint->getRequestBodyTemplate();
        if ($body !== null) {
            $body = $this->variableResolver->resolve($body, $variables);
        }

        $method = ($methodOverride ?? $endpoint->getMethod())->value;

        if ($this->urlValidator->isBlocked($url)) {
            return $this->failure($url, $method, $headers, $body, 'Request URL is not allowed (SSRF or allowlist).');
        }

        $start = hrtime(true);

        try {
            $options = [
                'headers' => $headers,
                'timeout' => $this->timeoutSeconds,
            ];
            if ($body !== null && $body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
                $options['body'] = $body;
            }

            $response = $this->httpClient->request($method, $url, $options);
            $content  = $response->getContent(false);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);

            return new ApiExecutionResult(
                requestUrl: $url,
                requestMethod: $method,
                requestHeaders: $headers,
                requestBody: $body,
                responseStatus: $response->getStatusCode(),
                responseHeaders: $this->flattenHeaders($response->getHeaders(false)),
                responseBody: $content,
                durationMs: $duration,
                success: $response->getStatusCode() >= 200 && $response->getStatusCode() < 400,
            );
        } catch (Throwable $e) {
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);

            return $this->failure($url, $method, $headers, $body, $e->getMessage(), $duration);
        }
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $overrideHeaders
     */
    private function executeSoap(
        ApiEndpoint $endpoint,
        ApiService $service,
        array $variables,
        ?string $overrideBody,
        array $overrideHeaders,
        bool $replaceHeaders = false,
    ): ApiExecutionResult {
        if (!extension_loaded('soap')) {
            return $this->failure('', 'POST', [], null, 'PHP SOAP extension is not installed.');
        }

        $wsdl    = rtrim($this->variableResolver->resolve($service->getBaseUrl(), $variables), '/');
        $body    = $overrideBody ?? $endpoint->getRequestBodyTemplate() ?? '';
        $body    = $this->variableResolver->resolve($body, $variables);
        $headers = $this->buildHeaders($service, $endpoint, $variables, $overrideHeaders);
        $action  = $endpoint->getSoapAction() ?? $endpoint->getName();

        if ($this->urlValidator->isBlocked($wsdl)) {
            return $this->failure($wsdl, 'SOAP', $headers, $body, 'WSDL URL is not allowed (SSRF or allowlist).');
        }

        $start = hrtime(true);

        try {
            $client = new SoapClient($wsdl, [
                'trace'              => true,
                'exceptions'         => true,
                'connection_timeout' => $this->timeoutSeconds,
            ]);

            $params = json_decode($body, true);
            if (!is_array($params)) {
                $params = [];
            }

            $result   = $client->__soapCall($action, [$params]);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return new ApiExecutionResult(
                requestUrl: $wsdl,
                requestMethod: 'SOAP',
                requestHeaders: $headers,
                requestBody: $client->__getLastRequest() ?: $body,
                responseStatus: 200,
                responseHeaders: ['Content-Type' => 'text/xml'],
                responseBody: $client->__getLastResponse() ?: (string) $response,
                durationMs: $duration,
                success: true,
            );
        } catch (Throwable $e) {
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);

            return $this->failure($wsdl, 'SOAP', $headers, $body, $e->getMessage(), $duration);
        }
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $overrideHeaders
     * @param array<string, string> $overrideQueryParams
     */
    private function executeGraphql(
        ApiEndpoint $endpoint,
        ApiService $service,
        array $variables,
        ?string $overrideBody,
        array $overrideHeaders,
        array $overrideQueryParams,
        bool $replaceQueryParams = false,
        bool $replaceHeaders = false,
    ): ApiExecutionResult {
        $body = $overrideBody ?? $endpoint->getRequestBodyTemplate();
        if ($body === null || $body === '') {
            $body = '{"query": "{}"}';
        }

        return $this->executeRest($endpoint, $service, $variables, $body, $overrideHeaders, $overrideQueryParams, HttpMethod::Post, $replaceQueryParams, $replaceHeaders);
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $overrideHeaders
     *
     * @return array<string, string>
     */
    private function buildHeaders(
        ApiService $service,
        ApiEndpoint $endpoint,
        array $variables,
        array $overrideHeaders,
        bool $replaceHeaders = false,
    ): array {
        $headers = $replaceHeaders
            ? $overrideHeaders
            : array_merge(
                $service->getDefaultHeaders(),
                $endpoint->getHeaders(),
                $overrideHeaders,
            );
        $headers = $this->variableResolver->resolveMap($headers, $variables);

        if (!isset($headers['Content-Type']) && $endpoint->getContentType() !== '') {
            $headers['Content-Type'] = $endpoint->getContentType();
        }

        $headers = $this->applyAuthHeaders($service, $variables, $headers);

        return $headers;
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function applyAuthHeaders(ApiService $service, array $variables, array $headers): array
    {
        $config = $service->getAuthConfig();

        return match ($service->getAuthType()) {
            AuthType::Basic  => $this->applyBasicAuth($config, $variables, $headers),
            AuthType::Bearer => $this->applyBearerAuth($config, $variables, $headers),
            AuthType::ApiKey => $this->applyApiKeyAuth($config, $variables, $headers),
            default          => $headers,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $variables
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function applyBasicAuth(array $config, array $variables, array $headers): array
    {
        $username = $this->variableResolver->resolve((string) ($config['username'] ?? ''), $variables);
        $password = $this->variableResolver->resolve((string) ($config['password'] ?? ''), $variables);
        if ($username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $variables
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function applyBearerAuth(array $config, array $variables, array $headers): array
    {
        $token = $this->variableResolver->resolve((string) ($config['token'] ?? ''), $variables);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $variables
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function applyApiKeyAuth(array $config, array $variables, array $headers): array
    {
        $headerName = (string) ($config['header'] ?? 'X-Api-Key');
        $value      = $this->variableResolver->resolve((string) ($config['value'] ?? ''), $variables);
        if ($value !== '') {
            $headers[$headerName] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, list<string>> $rawHeaders
     *
     * @return array<string, string>
     */
    private function flattenHeaders(array $rawHeaders): array
    {
        $flat = [];
        foreach ($rawHeaders as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }

    /**
     * @param array<string, string> $headers
     */
    private function failure(
        string $url,
        string $method,
        array $headers,
        ?string $body,
        string $message,
        int $durationMs = 0,
    ): ApiExecutionResult {
        return new ApiExecutionResult(
            requestUrl: $url,
            requestMethod: $method,
            requestHeaders: $headers,
            requestBody: $body,
            responseStatus: null,
            responseHeaders: [],
            responseBody: null,
            durationMs: $durationMs,
            success: false,
            errorMessage: $message,
        );
    }
}
