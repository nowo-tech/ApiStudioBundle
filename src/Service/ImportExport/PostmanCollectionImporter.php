<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Model\ImportResult;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

use function in_array;
use function is_array;
use function is_scalar;
use function is_string;

/**
 * Imports Postman Collection v2.0/v2.1 into services, endpoints, and variables.
 */
final class PostmanCollectionImporter
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
        bool $importVariables = true,
        ?ApiService $targetService = null,
    ): ImportResult {
        $data = $this->parser->parse($content, $filename);
        if (($data['info']['schema'] ?? '') === '' && !isset($data['item'])) {
            throw new InvalidArgumentException('Unsupported Postman collection format.');
        }

        $result = new ImportResult();
        if ($importVariables) {
            $result = $result->merge($this->importCollectionVariables($workspace, $data));
        }

        $collectionName = (string) ($data['info']['name'] ?? 'Postman Collection');
        $service        = $targetService ?? $this->createService($workspace, $collectionName, $this->resolveCollectionBaseUrl($data));

        $endpointsCreated = 0;
        $items            = $data['item'] ?? [];
        if (is_array($items)) {
            $this->walkItems(array_values($items), $service, $endpointsCreated);
        }

        if (!$targetService instanceof ApiService) {
            $this->entityManager->persist($service);
            $result = $result->merge(new ImportResult(servicesCreated: 1, endpointsCreated: $endpointsCreated));
        } else {
            $result = $result->merge(new ImportResult(endpointsCreated: $endpointsCreated));
        }

        return $result->merge(new ImportResult(messages: ['Imported Postman collection']));
    }

    /** @param list<mixed> $items */
    private function walkItems(array $items, ApiService $service, int &$endpointsCreated): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['item']) && is_array($item['item'])) {
                $this->walkItems(array_values($item['item']), $service, $endpointsCreated);

                continue;
            }

            if (!isset($item['request']) || !is_array($item['request'])) {
                continue;
            }

            $endpoint = $this->createEndpointFromRequest($service, $item);
            $this->entityManager->persist($endpoint);
            ++$endpointsCreated;
        }
    }

    /** @param array<string, mixed> $item */
    private function createEndpointFromRequest(ApiService $service, array $item): ApiEndpoint
    {
        $request       = $item['request'];
        $name          = (string) ($item['name'] ?? 'Request');
        $existingSlugs = array_map(static fn (ApiEndpoint $e): string => $e->getSlug(), $service->getEndpoints()->toArray());
        $slug          = SlugHelper::unique($name, $existingSlugs);

        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        if (!in_array($method, HttpMethod::values(), true)) {
            $method = 'GET';
        }

        [$path, $queryParams] = $this->parseUrl($request['url'] ?? '/');
        $endpoint             = new ApiEndpoint($name, $slug);
        $endpoint->setMethod(HttpMethod::from($method));
        $endpoint->setPath($path);
        $endpoint->setQueryParams($queryParams);

        $headers = [];
        foreach ($request['header'] ?? [] as $header) {
            if (!is_array($header) || !($header['enabled'] ?? true)) {
                continue;
            }
            $key = (string) ($header['key'] ?? '');
            if ($key !== '') {
                $headers[$key] = (string) ($header['value'] ?? '');
            }
        }
        $endpoint->setHeaders($headers);

        $body = $request['body'] ?? null;
        if (is_array($body)) {
            $mode = (string) ($body['mode'] ?? '');
            if ($mode === 'raw' && isset($body['raw']) && is_string($body['raw'])) {
                $endpoint->setRequestBodyTemplate($body['raw']);
                $endpoint->setContentType((string) ($body['options']['raw']['language'] ?? 'application/json'));
            }
        }

        $service->addEndpoint($endpoint);

        return $endpoint;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function parseUrl(mixed $url): array
    {
        if (is_string($url)) {
            // parse_url() stub PHPDoc is stricter than runtime; keep defensive checks in code.
            /** @var array<string, mixed> $parts */
            $parts    = parse_url($url) ?: [];
            $path     = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
            $rawQuery = [];
            parse_str(is_string($parts['query'] ?? null) ? $parts['query'] : '', $rawQuery);

            $query = [];
            foreach ($rawQuery as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $query[$key] = is_scalar($value) ? (string) $value : '';
            }

            return [$path, $query];
        }

        if (!is_array($url)) {
            return ['/', []];
        }

        if (isset($url['raw']) && is_string($url['raw'])) {
            return $this->parseUrl($url['raw']);
        }

        $pathSegments = $url['path'] ?? [];
        $path         = '/';
        if (is_array($pathSegments)) {
            $path = '/' . ltrim(implode('/', array_map(strval(...), $pathSegments)), '/');
        }

        $query = [];
        foreach ($url['query'] ?? [] as $param) {
            if (!is_array($param) || !($param['enabled'] ?? true)) {
                continue;
            }
            $key = (string) ($param['key'] ?? '');
            if ($key !== '') {
                $query[$key] = (string) ($param['value'] ?? '');
            }
        }

        return [$path, $query];
    }

    /** @param array<string, mixed> $data */
    private function importCollectionVariables(ApiWorkspace $workspace, array $data): ImportResult
    {
        $environment = $this->resolveDefaultEnvironment($workspace);
        $createdEnv  = false;
        if (!$environment instanceof ApiEnvironment) {
            $environment = new ApiEnvironment('Postman', 'postman');
            $environment->setIsDefault(true);
            $workspace->addEnvironment($environment);
            $this->entityManager->persist($environment);
            $createdEnv = true;
        }

        $created = 0;
        $updated = 0;
        foreach ($data['variable'] ?? [] as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            try {
                $key = VariableSyntax::normalizeKey(trim((string) ($variable['key'] ?? '')));
            } catch (InvalidArgumentException) {
                continue;
            }

            $existing = null;
            foreach ($environment->getVariables() as $item) {
                if ($item->getVariableKey() === $key) {
                    $existing = $item;
                    break;
                }
            }

            if (!$existing instanceof ApiEnvironmentVariable) {
                $existing = new ApiEnvironmentVariable($key, (string) ($variable['value'] ?? ''));
                $environment->addVariable($existing);
                ++$created;
            } else {
                $existing->setValue((string) ($variable['value'] ?? ''));
                ++$updated;
            }
        }

        return new ImportResult(
            variablesCreated: $created,
            variablesUpdated: $updated,
            environmentsCreated: $createdEnv ? 1 : 0,
        );
    }

    /** @param array<string, mixed> $data */
    private function resolveCollectionBaseUrl(array $data): string
    {
        foreach ($data['variable'] ?? [] as $variable) {
            if (!is_array($variable)) {
                continue;
            }
            if (in_array($variable['key'] ?? '', ['base_url', 'baseUrl', 'host'], true)) {
                return VariableSyntax::formatKey((string) $variable['key']);
            }
        }

        return VariableSyntax::formatKey('base_url');
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

    private function resolveDefaultEnvironment(ApiWorkspace $workspace): ?ApiEnvironment
    {
        foreach ($workspace->getEnvironments() as $environment) {
            if ($environment->isDefault()) {
                return $environment;
            }
        }

        return $workspace->getEnvironments()->first() ?: null;
    }
}
