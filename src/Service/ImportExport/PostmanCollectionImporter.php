<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Model\ImportResult;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

use function array_filter;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function trim;

/**
 * Imports Postman Collection v2.0/v2.1 into services, endpoints, and variables.
 */
final class PostmanCollectionImporter
{
    public function __construct(
        private readonly DocumentParser $parser,
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $foldersEnabled = false,
        private readonly int $maxDepth = 8,
        private readonly ?ApiFolderRepository $folderRepository = null,
        private readonly ?FolderAccess $folderAccess = null,
        private readonly bool $scriptsImportable = false,
    ) {
    }

    public function import(
        ApiWorkspace $workspace,
        string $content,
        ?string $filename = null,
        bool $importVariables = true,
        ?ApiService $targetService = null,
        bool $allowScripts = false,
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
        // 1.14: with folders the item groups become folders (depth capped) and auth is mapped; without, 1.13's flat walk.
        $folders = $this->foldersEnabled ? new FolderImportHelper($service, $this->entityManager, $this->folderRepository, $this->maxDepth, $this->folderAccess) : null;
        // 1.15: event[] scripts are kept only in sandbox mode, for a user allowed to edit scripts.
        $stats = ['scripts' => 0, 'auth' => 0, 'imported' => 0, 'withScripts' => $this->scriptsImportable && $allowScripts];
        if ($folders instanceof FolderImportHelper && !$targetService instanceof ApiService) {
            $collectionAuth = PostmanAuthMapper::map($data['auth'] ?? null);
            if ($collectionAuth !== null) {
                $service->setAuthType($collectionAuth['type']);
                $service->setAuthConfig($collectionAuth['config']);
            } elseif (PostmanAuthMapper::isPresent($data['auth'] ?? null)) {
                ++$stats['auth'];
            }
        }
        // Collection scripts land on the service only when this import creates it.
        $this->applyScripts($targetService instanceof ApiService ? null : $service, $data, $stats);
        if (is_array($items)) {
            $this->walkItems(array_values($items), $service, $endpointsCreated, $folders, null, $stats);
        }

        if (!$targetService instanceof ApiService) {
            $this->entityManager->persist($service);
            $result = $result->merge(new ImportResult(servicesCreated: 1, endpointsCreated: $endpointsCreated));
        } else {
            $result = $result->merge(new ImportResult(endpointsCreated: $endpointsCreated));
        }

        $messages = ['Imported Postman collection'];
        if ($folders instanceof FolderImportHelper) {
            if ($folders->mergedCount() > 0) {
                $messages[] = sprintf('%d folders deeper than the allowed depth were merged into their deepest allowed parent.', $folders->mergedCount());
            }
            if ($stats['auth'] > 0) {
                $messages[] = sprintf('%d auth settings of an unsupported type (or on a single request) were not imported.', $stats['auth']);
            }
        }

        if ($stats['imported'] > 0) {
            $messages[] = sprintf('%d scripts were imported (they run in the sandboxed runner).', $stats['imported']);
        }
        if ($stats['scripts'] > 0) {
            $messages[] = sprintf('%d scripts were not imported (they need scripts.mode: sandbox, the right to edit scripts and, for folder scripts, folders created by this import).', $stats['scripts']);
        }

        return $result->merge(new ImportResult(messages: $messages));
    }

    /**
     * Stores the `event[]` scripts of a collection / folder / request on $target (pre-request: `prerequest`, post:
     * `test`), or counts them as not imported when $target is null or scripts are not imported.
     *
     * @param array<string, mixed> $node
     * @param array{scripts: int, auth: int, imported: int, withScripts: bool} $stats
     */
    private function applyScripts(ApiService|ApiFolder|ApiEndpoint|null $target, array $node, array &$stats): void
    {
        $scripts = self::eventScripts($node);
        if ($scripts === []) {
            return;
        }
        if ($target === null || !$stats['withScripts']) {
            $stats['scripts'] += count($scripts);

            return;
        }
        foreach ($scripts as $listen => $code) {
            if ($listen === 'prerequest') {
                $target->setPreRequestScript($code);
            } elseif ($listen === 'test') {
                $target->setPostRequestScript($code);
            } else {
                ++$stats['scripts'];

                continue;
            }
            ++$stats['imported'];
        }
    }

    /**
     * Pre-request and test scripts of a collection / folder / request (`event[]` with `script.exec`, a string or a list
     * of lines), by `listen`; scripts of another kind count under their own name. The last one of a kind wins.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, string>
     */
    private static function eventScripts(array $node): array
    {
        $scripts = [];
        foreach (is_array($node['event'] ?? null) ? $node['event'] : [] as $event) {
            if (!is_array($event) || !is_array($event['script'] ?? null) || ($event['disabled'] ?? false) === true) {
                continue;
            }
            $exec = $event['script']['exec'] ?? null;
            $code = is_array($exec) ? implode("\n", array_filter($exec, is_string(...))) : (is_string($exec) ? $exec : '');
            if (trim($code) === '') {
                continue;
            }
            $listen           = is_string($event['listen'] ?? null) ? $event['listen'] : 'unknown';
            $scripts[$listen] = $code;
        }

        return $scripts;
    }

    /**
     * @param list<mixed> $items
     * @param array{scripts: int, auth: int, imported: int, withScripts: bool} $stats
     */
    private function walkItems(array $items, ApiService $service, int &$endpointsCreated, ?FolderImportHelper $folders, ?ApiFolder $parent, array &$stats): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['item']) && is_array($item['item'])) {
                $folder       = $parent;
                $scriptTarget = null;
                if ($folders instanceof FolderImportHelper) {
                    $description = $item['description'] ?? null;
                    $child       = $folders->child($parent, $item['name'] ?? null, is_string($description) ? $description : null);
                    if ($child instanceof ApiFolder) {
                        $folder       = $child;
                        $scriptTarget = $folders->isCreated($child) ? $child : null;
                        $auth         = PostmanAuthMapper::map($item['auth'] ?? null);
                        // RJ-M1: the imported auth only lands on a folder this import created, never on a reused one.
                        if (!$folders->isCreated($child)) {
                            if (PostmanAuthMapper::isPresent($item['auth'] ?? null)) {
                                ++$stats['auth'];
                            }
                        } elseif ($auth !== null) {
                            $folder->setAuthType($auth['type']);
                            $folder->setAuthConfig($auth['config']);
                        } elseif (PostmanAuthMapper::isPresent($item['auth'] ?? null)) {
                            ++$stats['auth'];
                        }
                    }
                }
                $this->applyScripts($scriptTarget, $item, $stats);
                $this->walkItems(array_values($item['item']), $service, $endpointsCreated, $folders, $folder, $stats);

                continue;
            }

            if (!isset($item['request']) || !is_array($item['request'])) {
                continue;
            }

            $endpoint = $this->createEndpointFromRequest($service, $item);
            $this->applyScripts($endpoint, $item, $stats);
            if ($folders instanceof FolderImportHelper) {
                $endpoint->setFolder($parent);
                $endpoint->setSortOrder($endpointsCreated * 10);
                $this->applyRequestAuth($endpoint, $item['request']['auth'] ?? null, $stats);
            }
            $this->entityManager->persist($endpoint);
            ++$endpointsCreated;
        }
    }

    /**
     * Request-level auth (1.14): endpoints have no auth of their own, so bearer and header API keys become endpoint
     * headers, but only when the credential is a `{{variable}}` placeholder (RJ-L3): a literal token would sit in a
     * plain header that every viewer and every OpenAPI export sees. Literal credentials and other types are reported.
     *
     * @param array{scripts: int, auth: int, imported: int, withScripts: bool} $stats
     */
    private function applyRequestAuth(ApiEndpoint $endpoint, mixed $auth, array &$stats): void
    {
        if (!PostmanAuthMapper::isPresent($auth)) {
            return;
        }

        $mapped  = PostmanAuthMapper::map($auth);
        $headers = $endpoint->getHeaders();
        if ($mapped !== null && $mapped['type'] === AuthType::Bearer && VariableSyntax::isPlaceholder($mapped['config']['token'])) {
            $headers['Authorization'] = 'Bearer ' . $mapped['config']['token'];
        } elseif ($mapped !== null && $mapped['type'] === AuthType::ApiKey && VariableSyntax::isPlaceholder($mapped['config']['value'])) {
            $headers[$mapped['config']['header']] = $mapped['config']['value'];
        } else {
            ++$stats['auth'];

            return;
        }
        $endpoint->setHeaders($headers);
    }

    /** @param array<string, mixed> $item */
    private function createEndpointFromRequest(ApiService $service, array $item): ApiEndpoint
    {
        $request       = $item['request'];
        $name          = FolderImportHelper::cleanName($item['name'] ?? null, 'Request');
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
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
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
                // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
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
