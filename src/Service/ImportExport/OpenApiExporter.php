<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\FolderAccess;

/**
 * Exports services/endpoints as OpenAPI 3.0 document.
 *
 * 1.14 folders: each operation in a folder carries its path as `x-api-studio-folder` (top-level folder first) and its
 * top-level folder as a tag (first tag of a service export, after the service tag in a workspace export); endpoints
 * of folders hidden by a host folder checker are left out.
 */
final class OpenApiExporter
{
    public function __construct(
        private readonly DocumentParser $parser,
        private readonly ?FolderAccess $folderAccess = null,
    ) {
    }

    public function exportWorkspace(ApiWorkspace $workspace): string
    {
        $paths = [];
        $tags  = [];

        foreach ($workspace->getServices() as $service) {
            if ($service->getProtocol()->value !== 'rest') {
                continue;
            }

            $tag    = $service->getName();
            $tags[] = ['name' => $tag, 'description' => $service->getDescription() ?? ''];

            foreach ($service->getEndpoints() as $endpoint) {
                if ($this->visible($endpoint)) {
                    $this->appendEndpoint($paths, $endpoint, $tag, false);
                }
            }
        }

        $document = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => $workspace->getName(),
                'description' => $workspace->getDescription() ?? 'Exported from API Studio',
                'version'     => '1.0.0',
            ],
            'tags'  => $tags,
            'paths' => $paths,
        ];

        return $this->parser->encodeJson($document);
    }

    public function exportService(ApiService $service): string
    {
        $paths      = [];
        $folderTags = [];
        foreach ($service->getEndpoints() as $endpoint) {
            if (!$this->visible($endpoint)) {
                continue;
            }
            $this->appendEndpoint($paths, $endpoint, $service->getName(), true);
            $top = $endpoint->getFolder()?->getPath()[0] ?? null;
            if ($top instanceof ApiFolder) {
                $folderTags[$top->getName()] ??= ['name' => $top->getName(), 'description' => $top->getDescription() ?? ''];
            }
        }

        $document = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => $service->getName(),
                'description' => $service->getDescription() ?? 'Exported from API Studio',
                'version'     => '1.0.0',
            ],
            'servers' => [['url' => $service->getBaseUrl()]],
            'paths'   => $paths,
        ];
        if ($folderTags !== []) {
            $document['tags'] = array_values($folderTags);
        }

        return $this->parser->encodeJson($document);
    }

    /** @param array<string, mixed> $paths */
    private function appendEndpoint(array &$paths, ApiEndpoint $endpoint, string $tag, bool $folderTagFirst): void
    {
        $path    = $endpoint->getPath() ?: '/';
        $method  = strtolower($endpoint->getMethod()->value);
        $folders = array_map(static fn (ApiFolder $folder): string => $folder->getName(), $endpoint->getFolder()?->getPath() ?? []);
        $tags    = [$tag];
        if ($folders !== []) {
            $tags = $folderTagFirst ? [$folders[0]] : [$tag, $folders[0]];
        }
        $operation = [
            'tags'        => $tags,
            'summary'     => $endpoint->getName(),
            'operationId' => $endpoint->getSlug(),
        ];
        if ($folders !== []) {
            $operation[OpenApiImporter::FOLDER_EXTENSION] = $folders;
        }

        $parameters = [];
        foreach ($endpoint->getQueryParams() as $name => $value) {
            $parameters[] = [
                'name'    => $name,
                'in'      => 'query',
                'schema'  => ['type' => 'string'],
                'example' => $value,
            ];
        }
        foreach ($endpoint->getHeaders() as $name => $value) {
            $parameters[] = [
                'name'    => $name,
                'in'      => 'header',
                'schema'  => ['type' => 'string'],
                'example' => $value,
            ];
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($endpoint->getRequestBodyTemplate()) {
            $operation['requestBody'] = [
                'content' => [
                    $endpoint->getContentType() => [
                        'schema'  => ['type' => 'string'],
                        'example' => $endpoint->getRequestBodyTemplate(),
                    ],
                ],
            ];
        }

        foreach ($endpoint->getResponseExamples() as $example) {
            $operation['responses'] ??= [];
            $operation['responses'][(string) $example->getStatusCode()] = [
                'description' => $example->getName(),
                'content'     => [
                    'application/json' => [
                        'example' => json_decode($example->getResponseBody() ?? 'null'),
                    ],
                ],
            ];
        }
        $operation['responses'] ??= ['200' => ['description' => 'Successful response']];

        $paths[$path] ??= [];
        $paths[$path][$method] = $operation;
    }

    private function visible(ApiEndpoint $endpoint): bool
    {
        return !$this->folderAccess instanceof FolderAccess || $this->folderAccess->canEndpoint(ApiStudioAction::WorkspaceView, $endpoint);
    }
}
