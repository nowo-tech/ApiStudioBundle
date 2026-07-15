<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service\ImportExport;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

/**
 * Exports services/endpoints as OpenAPI 3.0 document.
 */
final class OpenApiExporter
{
    public function __construct(
        private readonly DocumentParser $parser,
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
                $this->appendEndpoint($paths, $endpoint, $tag);
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
        $paths = [];
        foreach ($service->getEndpoints() as $endpoint) {
            $this->appendEndpoint($paths, $endpoint, $service->getName());
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

        return $this->parser->encodeJson($document);
    }

    /** @param array<string, mixed> $paths */
    private function appendEndpoint(array &$paths, ApiEndpoint $endpoint, string $tag): void
    {
        $path      = $endpoint->getPath() ?: '/';
        $method    = strtolower($endpoint->getMethod()->value);
        $operation = [
            'tags'        => [$tag],
            'summary'     => $endpoint->getName(),
            'operationId' => $endpoint->getSlug(),
        ];

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
}
