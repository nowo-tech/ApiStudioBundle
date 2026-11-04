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
use Nowo\ApiStudioBundle\Soap\GuardedSoapClient;
use Nowo\ApiStudioBundle\Soap\GuardedWsdlLoader;
use Nowo\ApiStudioBundle\Soap\SoapEnvelope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function extension_loaded;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;
use const WSDL_CACHE_NONE;

/**
 * Executes REST, SOAP, and GraphQL requests with environment variable substitution.
 */
final class RequestExecutor
{
    private readonly LoggerInterface $logger;

    private readonly HttpClientInterface $httpClient;

    /**
     * @param int $maxRedirects Redirects to follow (execution_max_redirects). Default 0: 3xx responses are returned
     *                          as-is; followed hops are still checked against private networks (not the allowlist).
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private readonly VariableResolver $variableResolver,
        private readonly ExecutionUrlValidator $urlValidator,
        private readonly int $timeoutSeconds,
        ?LoggerInterface $logger = null,
        private readonly int $maxRedirects = 0,
        private readonly int $maxResponseBytes = ResponseBodyReader::DEFAULT_MAX_BYTES,
    ) {
        $this->logger = $logger ?? new NullLogger();
        // Re-checks the connected IP (DNS rebinding) and every redirect hop, which the pre-flight validator cannot.
        $this->httpClient = $httpClient instanceof NoPrivateNetworkHttpClient
            ? $httpClient
            : new NoPrivateNetworkHttpClient($httpClient, ExecutionUrlValidator::BLOCKED_SUBNETS);
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

        // Fail closed: a secret that cannot be decrypted has no value, so a request that needs it is never sent (it
        // would carry the bare placeholder). A value the caller supplied for that key (console override) is used.
        // An empty value or a placeholder (`{{ token }}`, spaced or not) is no value: the key stays unavailable.
        $unreadable = array_values(array_filter(
            $environment?->getUnreadableVariableKeys() ?? [],
            static fn (string $key): bool => ($variableMap[$key] ?? '') === '' || VariableSyntax::isPlaceholder($variableMap[$key]),
        ));
        if ($unreadable !== []) {
            $needed = array_values(array_intersect($unreadable, self::referencedVariables($endpoint, $service, $overrideBody, $overrideHeaders, $overrideQueryParams, $replaceQueryParams, $replaceHeaders)));
            if ($needed !== []) {
                return $this->unreadableSecretFailure($endpoint, $methodOverride, $needed);
            }
        }

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
            $this->logFailure($method, $url, 'Request URL is not allowed (SSRF or allowlist).');

            return $this->failure($url, $method, $headers, $body, 'Request URL is not allowed (SSRF or allowlist).');
        }

        $this->logStart($method, $url);
        $start = hrtime(true);

        try {
            $options = [
                'headers'       => $headers,
                'timeout'       => $this->timeoutSeconds,
                'max_duration'  => $this->timeoutSeconds,
                'max_redirects' => $this->maxRedirects,
            ];
            if ($body !== null && $body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
                $options['body'] = $body;
            }

            $response = $this->httpClient->request($method, $url, $options);
            $content  = ResponseBodyReader::read($this->httpClient, $response, $this->maxResponseBytes);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $status   = $response->getStatusCode();
            $success  = $status >= 200 && $status < 400;

            $this->logFinish($method, $url, $success, $status, $duration);

            return new ApiExecutionResult(
                requestUrl: $url,
                requestMethod: $method,
                requestHeaders: $headers,
                requestBody: $body,
                responseStatus: $status,
                responseHeaders: $this->flattenHeaders($response->getHeaders(false)),
                responseBody: $content,
                durationMs: $duration,
                success: $success,
            );
        } catch (Throwable $e) {
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->logFailure($method, $url, $e->getMessage(), $duration);

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
        $wsdl = rtrim($this->variableResolver->resolve($service->getBaseUrl(), $variables), '/');
        $body = $overrideBody ?? $endpoint->getRequestBodyTemplate() ?? '';
        $body = $this->variableResolver->resolve($body, $variables);
        // Auth, default and console headers are sent over HTTP like for REST; Content-Type and SOAPAction belong to
        // the SOAP transport (envelope version + operation), so they are dropped here and never overridden.
        // SoapEnvelope (not GuardedSoapClient) so raw-envelope calls work without ext-soap.
        $headers = SoapEnvelope::withoutFramingHeaders(
            $this->buildHeaders($service, $endpoint, $variables, $overrideHeaders, $replaceHeaders),
        );
        $action = $endpoint->getSoapAction() ?? $endpoint->getName();

        // Raw-envelope mode: a complete SOAP envelope is POSTed as-is (no WSDL, no ext-soap needed).
        if (SoapEnvelope::isEnvelope($body)) {
            return $this->executeSoapEnvelope(SoapEnvelope::endpointUrl($wsdl), $action, $body, $headers);
        }

        if (!extension_loaded('soap')) {
            return $this->failure('', 'SOAP', [], null, 'PHP SOAP extension is not installed (required for JSON-parameter SOAP calls; send a full SOAP envelope as body to call without it).');
        }

        if ($this->urlValidator->isBlocked($wsdl)) {
            $this->logFailure('SOAP', $wsdl, 'WSDL URL is not allowed (SSRF or allowlist).');

            return $this->failure($wsdl, 'SOAP', $headers, $body, 'WSDL URL is not allowed (SSRF or allowlist).');
        }

        $this->logStart('SOAP', $wsdl);
        $start = hrtime(true);

        // ext-soap never touches the network itself: the WSDL (and its imports) and every SOAP call go through
        // the guarded HTTP client, so the <soap:address location>, redirects and DNS rebinding are checked too.
        $wsdlLoader = new GuardedWsdlLoader($this->httpClient, $this->urlValidator, $this->timeoutSeconds, $this->maxRedirects, $headers, $this->maxResponseBytes);

        try {
            $client = new GuardedSoapClient($wsdlLoader->load($wsdl), [
                'trace'      => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ], $this->httpClient, $this->urlValidator, $this->timeoutSeconds, $this->maxRedirects, $headers, $this->maxResponseBytes, GuardedWsdlLoader::origin($wsdl));

            $params = json_decode($body, true);
            if (!is_array($params)) {
                $params = [];
            }

            $result   = $client->__soapCall(SoapEnvelope::operationName($action), [$params]);
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $this->logFinish('SOAP', $wsdl, true, 200, $duration);

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
            $this->logFailure('SOAP', $wsdl, $e->getMessage(), $duration);

            return $this->failure($wsdl, 'SOAP', $headers, $body, $e->getMessage(), $duration);
        } finally {
            $wsdlLoader->cleanup();
        }
    }

    /**
     * POSTs a complete SOAP envelope through the guarded HTTP client (SSRF/allowlist pre-check, private network and
     * rebinding checks, timeout, redirect policy). A SOAP Fault (usually HTTP 500) or a non-2xx status is a failure.
     *
     * @param array<string, string> $headers extra HTTP headers, framing headers already removed
     */
    private function executeSoapEnvelope(string $url, string $action, string $envelope, array $headers): ApiExecutionResult
    {
        $headers += SoapEnvelope::framingHeaders($action, SoapEnvelope::version($envelope));

        if ($this->urlValidator->isBlocked($url)) {
            $this->logFailure('SOAP', $url, 'SOAP endpoint URL is not allowed (SSRF or allowlist).');

            return $this->failure($url, 'SOAP', $headers, $envelope, 'SOAP endpoint URL is not allowed (SSRF or allowlist).');
        }

        $this->logStart('SOAP', $url);
        $start = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers'       => $headers,
                'body'          => $envelope,
                'timeout'       => $this->timeoutSeconds,
                'max_duration'  => $this->timeoutSeconds,
                'max_redirects' => $this->maxRedirects,
            ]);
            $content  = ResponseBodyReader::read($this->httpClient, $response, $this->maxResponseBytes);
            $status   = $response->getStatusCode();
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);

            $error = match (true) {
                $status >= 300 && $status < 400 => 'SOAP endpoint answered with a redirect (HTTP ' . $status . ') that was not followed.',
                default                         => SoapEnvelope::fault($content)
                    ?? ($status >= 200 && $status < 300 ? null : 'SOAP endpoint answered with HTTP ' . $status . '.'),
            };

            $this->logFinish('SOAP', $url, $error === null, $status, $duration);

            return new ApiExecutionResult(
                requestUrl: $url,
                requestMethod: 'SOAP',
                requestHeaders: $headers,
                requestBody: $envelope,
                responseStatus: $status,
                responseHeaders: $this->flattenHeaders($response->getHeaders(false)),
                responseBody: $content,
                durationMs: $duration,
                success: $error === null,
                errorMessage: $error,
            );
        } catch (Throwable $e) {
            $duration = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->logFailure('SOAP', $url, $e->getMessage(), $duration);

            return $this->failure($url, 'SOAP', $headers, $envelope, $e->getMessage(), $duration);
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
     * Variable keys the request templates reference ({{name}}): base URL, path, query parameters, headers, body and the
     * service auth configuration, after the console overrides (same precedence as the request itself).
     *
     * @param array<string, string> $overrideHeaders
     * @param array<string, string> $overrideQueryParams
     *
     * @return list<string>
     */
    public static function referencedVariables(
        ApiEndpoint $endpoint,
        ApiService $service,
        ?string $overrideBody = null,
        array $overrideHeaders = [],
        array $overrideQueryParams = [],
        bool $replaceQueryParams = false,
        bool $replaceHeaders = false,
    ): array {
        $templates = [
            $service->getBaseUrl(),
            $endpoint->getPath(),
            (string) ($overrideBody ?? $endpoint->getRequestBodyTemplate()),
            ...array_values($replaceQueryParams ? $overrideQueryParams : array_merge($endpoint->getQueryParams(), $overrideQueryParams)),
            ...array_values($replaceHeaders ? $overrideHeaders : array_merge($service->getDefaultHeaders(), $endpoint->getHeaders(), $overrideHeaders)),
        ];
        $authConfig = $service->getAuthConfig();
        array_walk_recursive($authConfig, static function (mixed $value) use (&$templates): void {
            if (is_string($value)) {
                $templates[] = $value;
            }
        });

        $keys = [];
        foreach ($templates as $template) {
            if (preg_match_all(VariableSyntax::PLACEHOLDER_PATTERN, $template, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Variables a runner without `workspace.edit` may not change (ER-M1): those that decide where the request goes or
     * which credential it carries — referenced in the base URL, the path, the endpoint query parameters, the auth
     * configuration, or a service / endpoint header that also references a secret variable. Every variable of the
     * URL is protected, so the request origin (scheme, host, port) cannot change; the resolver is single-pass, so an
     * override cannot smuggle another placeholder in.
     *
     * @return list<string>
     */
    public static function protectedVariables(ApiEndpoint $endpoint, ApiService $service, ?ApiEnvironment $environment): array
    {
        $secrets = [];
        foreach ($environment?->getVariables() ?? [] as $variable) {
            if ($variable->isSecret()) {
                $secrets[$variable->getVariableKey()] = true;
            }
        }

        $templates  = [$service->getBaseUrl(), $endpoint->getPath(), ...array_values($endpoint->getQueryParams())];
        $authConfig = $service->getAuthConfig();
        array_walk_recursive($authConfig, static function (mixed $value) use (&$templates): void {
            if (is_string($value)) {
                $templates[] = $value;
            }
        });
        foreach ([...array_values($service->getDefaultHeaders()), ...array_values($endpoint->getHeaders())] as $header) {
            foreach (self::keysIn($header) as $key) {
                if (isset($secrets[$key])) {
                    $templates[] = $header;

                    break;
                }
            }
        }

        $keys = [];
        foreach ($templates as $template) {
            foreach (self::keysIn($template) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return list<string>
     */
    private static function keysIn(string $template): array
    {
        return preg_match_all(VariableSyntax::PLACEHOLDER_PATTERN, $template, $matches) > 0 ? $matches[1] : [];
    }

    /**
     * @param list<string> $keys
     */
    private function unreadableSecretFailure(ApiEndpoint $endpoint, ?HttpMethod $methodOverride, array $keys): ApiExecutionResult
    {
        $this->logger->warning('Api Studio request not sent: it needs a secret variable that cannot be decrypted', [
            'bundle'      => 'api-studio',
            'action'      => 'execute_secret_unreadable',
            'endpoint_id' => $endpoint->getId(),
            'variables'   => $keys,
        ]);

        return new ApiExecutionResult(
            requestUrl: '',
            requestMethod: ($methodOverride ?? $endpoint->getMethod())->value,
            requestHeaders: [],
            requestBody: null,
            responseStatus: null,
            responseHeaders: [],
            responseBody: null,
            durationMs: 0,
            success: false,
            errorMessage: sprintf(
                'Secret variable %s cannot be decrypted with the configured key: re-enter it in the environment. The request was not sent.',
                implode(', ', array_map(VariableSyntax::formatKey(...), $keys)),
            ),
            errorCode: ApiExecutionResult::ERROR_SECRET_UNREADABLE,
            unreadableVariables: $keys,
        );
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

    private function logStart(string $method, string $url): void
    {
        $this->logger->info('Api Studio outbound request started', [
            'bundle' => 'api-studio',
            'action' => 'execute_start',
            'method' => $method,
            'host'   => $this->safeHost($url),
        ]);
    }

    private function logFinish(string $method, string $url, bool $success, ?int $status, int $durationMs): void
    {
        $context = [
            'bundle'      => 'api-studio',
            'action'      => 'execute_finish',
            'method'      => $method,
            'host'        => $this->safeHost($url),
            'success'     => $success,
            'status'      => $status,
            'duration_ms' => $durationMs,
        ];

        if ($success) {
            $this->logger->info('Api Studio outbound request finished', $context);
        } else {
            $this->logger->warning('Api Studio outbound request finished with non-success status', $context);
        }
    }

    private function logFailure(string $method, string $url, string $message, int $durationMs = 0): void
    {
        $this->logger->error('Api Studio outbound request failed', [
            'bundle'      => 'api-studio',
            'action'      => 'execute_failure',
            'method'      => $method,
            'host'        => $this->safeHost($url),
            'duration_ms' => $durationMs,
            // Do not log full exception messages that may contain tokens; keep a short class-safe hint.
            'error' => $message,
        ]);
    }

    private function safeHost(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '(unknown)';
        }

        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'https';

        return $scheme . '://' . $host;
    }
}
