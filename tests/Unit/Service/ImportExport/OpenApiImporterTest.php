<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiImporter;
use Nowo\ApiStudioBundle\Service\ImportExport\SlugHelper;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('https://api.example.com', $service->getBaseUrl());
        self::assertCount(2, $service->getEndpoints());
    }

    public function testSlugHelperUnique(): void
    {
        self::assertSame('list_pets', SlugHelper::fromString('List pets'));
        self::assertSame('list_pets_2', SlugHelper::unique('List pets', ['list_pets']));
    }
}
