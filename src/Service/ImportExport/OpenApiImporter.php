<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Model\ImportResult;

use function in_array;
use function is_array;
use function is_scalar;
use function is_string;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Imports OpenAPI 3.x and Swagger 2.0 documents into services/endpoints.
 */
final class OpenApiImporter
{
    public function __construct(
        private readonly DocumentParser $parser,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function import(
        ApiWorkspace $workspace,
        string $content,
        ?string $filename = null,
        ?ApiService $targetService = null,
    ): ImportResult {
        $data = $this->parser->parse($content, $filename);

        if (isset($data['swagger'])) {
            return $this->importSwagger2($workspace, $data, $targetService);
        }

        if (isset($data['openapi'])) {
            return $this->importOpenApi3($workspace, $data, $targetService);
        }

        throw new InvalidArgumentException('Unsupported document: expected openapi or swagger root key.');
    }

    /** @param array<string, mixed> $data */
    private function importOpenApi3(ApiWorkspace $workspace, array $data, ?ApiService $targetService): ImportResult
    {
        $info    = $data['info'] ?? [];
        $service = $targetService ?? $this->createService(
            $workspace,
            (string) ($info['title'] ?? 'Imported API'),
            $this->resolveOpenApi3BaseUrl($data),
        );

        $endpointsCreated = 0;
        foreach ($data['paths'] ?? [] as $path => $operations) {
            if (!is_string($path) || !is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (!is_array($operation) || !$this->isHttpMethod((string) $method)) {
                    continue;
                }

                $endpoint = $this->createEndpoint($service, (string) $method, $path, $operation);
                $this->entityManager->persist($endpoint);
                ++$endpointsCreated;
            }
        }

        if (!$targetService instanceof ApiService) {
            $this->entityManager->persist($service);
        }

        return new ImportResult(
            servicesCreated: $targetService instanceof ApiService ? 0 : 1,
            endpointsCreated: $endpointsCreated,
            messages: ['Imported OpenAPI ' . ($data['openapi'] ?? '3.x')],
        );
    }

    /** @param array<string, mixed> $data */
    private function importSwagger2(ApiWorkspace $workspace, array $data, ?ApiService $targetService): ImportResult
    {
        $info     = $data['info'] ?? [];
        $basePath = (string) ($data['basePath'] ?? '');
        $host     = (string) ($data['host'] ?? '');
        $schemes  = $data['schemes'][0] ?? 'https';
        $baseUrl  = $host !== '' ? $schemes . '://' . $host . $basePath : '{{base_url}}';

        $service = $targetService ?? $this->createService(
            $workspace,
            (string) ($info['title'] ?? 'Imported Swagger API'),
            $baseUrl,
        );

        $endpointsCreated = 0;
        foreach ($data['paths'] ?? [] as $path => $operations) {
            if (!is_string($path) || !is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (!is_array($operation) || !$this->isHttpMethod((string) $method)) {
                    continue;
                }

                $endpoint = $this->createEndpoint($service, (string) $method, $path, $operation);
                $this->entityManager->persist($endpoint);
                ++$endpointsCreated;
            }
        }

        if (!$targetService instanceof ApiService) {
            $this->entityManager->persist($service);
        }

        return new ImportResult(
            servicesCreated: $targetService instanceof ApiService ? 0 : 1,
            endpointsCreated: $endpointsCreated,
            messages: ['Imported Swagger ' . ($data['swagger'] ?? '2.0')],
        );
    }

    /** @param array<string, mixed> $operation */
    private function createEndpoint(ApiService $service, string $method, string $path, array $operation): ApiEndpoint
    {
        $name          = (string) ($operation['summary'] ?? $operation['operationId'] ?? strtoupper($method) . ' ' . $path);
        $existingSlugs = array_map(static fn (ApiEndpoint $e): string => $e->getSlug(), $service->getEndpoints()->toArray());
        $slug          = SlugHelper::unique($name, $existingSlugs);

        $endpoint = new ApiEndpoint($name, $slug);
        $endpoint->setMethod(HttpMethod::from(strtoupper($method)));
        $endpoint->setPath($path);

        $queryParams = [];
        $headers     = [];
        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $in        = (string) ($parameter['in'] ?? 'query');
            $paramName = (string) ($parameter['name'] ?? '');
            if ($paramName === '') {
                continue;
            }

            $example = $parameter['example'] ?? ($parameter['schema']['example'] ?? '');
            $value   = is_scalar($example) ? (string) $example : '';

            if ($in === 'header') {
                $headers[$paramName] = $value;
            } else {
                $queryParams[$paramName] = $value;
            }
        }

        $endpoint->setQueryParams($queryParams);
        $endpoint->setHeaders($headers);

        $body = $this->extractRequestBody($operation);
        if ($body !== null) {
            $endpoint->setRequestBodyTemplate($body);
        }

        $service->addEndpoint($endpoint);

        return $endpoint;
    }

    /** @param array<string, mixed> $operation */
    private function extractRequestBody(array $operation): ?string
    {
        if (isset($operation['requestBody']) && is_array($operation['requestBody'])) {
            foreach ($operation['requestBody']['content'] ?? [] as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (isset($content['example'])) {
                    return $this->stringifyExample($content['example']);
                }
            }
        }

        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (!is_array($parameter) || ($parameter['in'] ?? '') !== 'body') {
                continue;
            }
            if (isset($parameter['schema']['example'])) {
                return $this->stringifyExample($parameter['schema']['example']);
            }
        }

        return null;
    }

    private function stringifyExample(mixed $example): string
    {
        if (is_string($example)) {
            return $example;
        }

        return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @param array<string, mixed> $data */
    private function resolveOpenApi3BaseUrl(array $data): string
    {
        $server = $data['servers'][0]['url'] ?? null;
        if (is_string($server) && $server !== '') {
            return $server;
        }

        return '{{base_url}}';
    }

    private function createService(ApiWorkspace $workspace, string $name, string $baseUrl): ApiService
    {
        $existing = array_map(static fn (ApiService $s): string => $s->getSlug(), $workspace->getServices()->toArray());
        $service  = new ApiService($name, SlugHelper::unique($name, $existing));
        $service->setProtocol(ApiProtocol::Rest);
        $service->setBaseUrl($baseUrl);
        $workspace->addService($service);

        return $service;
    }

    private function isHttpMethod(string $method): bool
    {
        return in_array(strtoupper($method), HttpMethod::values(), true);
    }
}
