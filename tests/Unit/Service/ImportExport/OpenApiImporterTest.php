<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiImporter;
use Nowo\ApiStudioBundle\Service\ImportExport\SlugHelper;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class OpenApiImporterTest extends TestCase
{
    public function testImportsOpenApi3Paths(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $em        = $this->createMock(EntityManagerInterface::class);
        $importer  = new OpenApiImporter(new DocumentParser(), $em);

        $document = <<<'JSON'
{
  "openapi": "3.0.0",
  "info": {"title": "Pet API", "version": "1.0.0"},
  "servers": [{"url": "https://api.example.com"}],
  "paths": {
    "/pets": {
      "get": {"summary": "List pets", "parameters": [{"name": "limit", "in": "query", "schema": {"type": "integer"}, "example": "10"}]},
      "post": {"summary": "Create pet", "requestBody": {"content": {"application/json": {"example": {"name": "cat"}}}}}
    }
  }
}
JSON;

        $result = $importer->import($workspace, $document, 'openapi.json');

        self::assertSame(1, $result->servicesCreated);
        self::assertSame(2, $result->endpointsCreated);
        self::assertCount(1, $workspace->getServices());
        $service = $workspace->getServices()->first();
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame('https://api.example.com', $service->getBaseUrl());
        self::assertCount(2, $service->getEndpoints());
    }

    public function testSlugHelperUnique(): void
    {
        self::assertSame('list_pets', SlugHelper::fromString('List pets'));
        self::assertSame('list_pets_2', SlugHelper::unique('List pets', ['list_pets']));
    }

    public function testImportsSwagger2IntoTargetServiceWithParametersAndBodies(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $target    = new ApiService('Existing', 'existing');
        $workspace->addService($target);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(4))->method('persist');

        $document = json_encode([
            'swagger' => '2.0',
            'host'    => 'api.example.test',
            'paths'   => [
                '/items' => [
                    'get' => ['operationId' => 'listItems', 'parameters' => [
                        ['name' => 'X-Trace', 'in' => 'header', 'schema' => ['example' => 'abc']],
                        ['name' => 'filter', 'in' => 'query', 'example' => ['complex']],
                        ['in' => 'query'],
                        'bad',
                    ]],
                    'post' => ['summary' => 'Create item', 'parameters' => [
                        ['in' => 'query', 'name' => 'dry'],
                        ['in' => 'body', 'name' => 'payload', 'schema' => ['example' => ['name' => 'x']]],
                    ]],
                    'put'         => ['parameters' => [['in' => 'body', 'name' => 'payload', 'schema' => []]], 'requestBody' => ['content' => ['bad', 'application/json' => ['schema' => []]]]],
                    'patch'       => ['requestBody' => ['content' => ['text/plain' => ['example' => 'raw text']]]],
                    'parameters'  => [['name' => 'ignored']],
                    'x-extension' => 'ignored',
                ],
                '/broken' => 'not-an-array',
            ],
        ], JSON_THROW_ON_ERROR);

        $result = (new OpenApiImporter(new DocumentParser(), $em))->import($workspace, $document, null, $target);

        self::assertSame(0, $result->servicesCreated);
        self::assertSame(4, $result->endpointsCreated);
        self::assertSame(['Imported Swagger 2.0'], $result->messages);
        $endpoints = $target->getEndpoints()->toArray();
        self::assertSame(['listItems', 'Create item', 'PUT /items', 'PATCH /items'], array_map(static fn (ApiEndpoint $e): string => $e->getName(), $endpoints));
        self::assertSame(['X-Trace' => 'abc'], $endpoints[0]->getHeaders());
        self::assertSame(['filter' => ''], $endpoints[0]->getQueryParams());
        self::assertSame("{\n    \"name\": \"x\"\n}", $endpoints[1]->getRequestBodyTemplate());
        self::assertNull($endpoints[2]->getRequestBodyTemplate());
        self::assertSame('raw text', $endpoints[3]->getRequestBodyTemplate());
    }

    public function testSwagger2BaseUrlAndDefaults(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $importer  = new OpenApiImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class));

        $result = $importer->import($workspace, '{"swagger": "2.0", "info": {"title": "Pets"}, "host": "pets.example.test", "basePath": "/v2", "schemes": ["http"]}');
        self::assertSame(1, $result->servicesCreated);
        $importer->import($workspace, '{"swagger": "2.0"}');
        $importer->import($workspace, '{"openapi": "3.1.0", "servers": [{"url": ""}], "paths": {"/a": {"trace": {}}}}');

        $services = $workspace->getServices()->toArray();
        self::assertSame('http://pets.example.test/v2', $services[0]->getBaseUrl());
        self::assertSame('Imported Swagger API', $services[1]->getName());
        self::assertSame('{{base_url}}', $services[1]->getBaseUrl());
        self::assertSame('Imported API', $services[2]->getName());
        self::assertSame('{{base_url}}', $services[2]->getBaseUrl());
        self::assertCount(0, $services[2]->getEndpoints());
    }

    public function testOpenApi3IntoTargetService(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $target    = new ApiService('Existing', 'existing');
        $workspace->addService($target);

        $result = (new OpenApiImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->import($workspace, '{"openapi": "3.0.0", "paths": {"/a": {"get": {}}, "/b": "x"}}', null, $target);

        self::assertSame(0, $result->servicesCreated);
        self::assertSame(1, $result->endpointsCreated);
        self::assertSame(['Imported OpenAPI 3.0.0'], $result->messages);
        self::assertCount(1, $workspace->getServices());
    }

    public function testRejectsUnknownDocuments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OpenApiImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->import(new ApiWorkspace('Demo', 'demo'), '{"asyncapi": "2.0"}');
    }
}
