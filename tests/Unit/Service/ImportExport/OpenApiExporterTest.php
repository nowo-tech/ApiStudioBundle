<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiExporter;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class OpenApiExporterTest extends TestCase
{
    public function testExportsRestServicesOfWorkspace(): void
    {
        $workspace = (new ApiWorkspace('Shop', 'shop'))->setDescription('Shop APIs');
        $rest      = (new ApiService('Orders', 'orders'))->setDescription('Order service');
        $soap      = (new ApiService('Billing', 'billing'))->setProtocol(ApiProtocol::Soap);
        $soap->addEndpoint(new ApiEndpoint('Invoice', 'invoice'));
        $workspace->addService($rest)->addService($soap);

        $list = (new ApiEndpoint('List orders', 'list_orders'))
            ->setPath('/orders')
            ->setQueryParams(['page' => '1'])
            ->setHeaders(['Accept' => 'application/json']);
        $list->addResponseExample((new ApiResponseExample('OK'))->setResponseBody('{"items":[]}'));
        $list->addResponseExample(new ApiResponseExample('Not found', 404));
        $create = (new ApiEndpoint('Create order', 'create_order'))
            ->setMethod(HttpMethod::Post)
            ->setPath('/orders')
            ->setRequestBodyTemplate('{"sku":"A1"}');
        $root = (new ApiEndpoint('Root', 'root'))->setPath('');
        $rest->addEndpoint($list)->addEndpoint($create)->addEndpoint($root);

        $document = $this->decode((new OpenApiExporter(new DocumentParser()))->exportWorkspace($workspace));

        self::assertSame('3.0.3', $document['openapi']);
        self::assertSame(['title' => 'Shop', 'description' => 'Shop APIs', 'version' => '1.0.0'], $document['info']);
        self::assertSame([['name' => 'Orders', 'description' => 'Order service']], $document['tags']);
        self::assertSame(['/orders', '/'], array_keys($document['paths']));

        $get = $document['paths']['/orders']['get'];
        self::assertSame(['Orders'], $get['tags']);
        self::assertSame('list_orders', $get['operationId']);
        self::assertSame([
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'string'], 'example' => '1'],
            ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string'], 'example' => 'application/json'],
        ], $get['parameters']);
        self::assertSame(['items' => []], $get['responses']['200']['content']['application/json']['example']);
        self::assertNull($get['responses']['404']['content']['application/json']['example']);

        $post = $document['paths']['/orders']['post'];
        self::assertArrayNotHasKey('parameters', $post);
        self::assertSame('{"sku":"A1"}', $post['requestBody']['content']['application/json']['example']);
        self::assertSame(['200' => ['description' => 'Successful response']], $post['responses']);
    }

    public function testExportsSingleServiceWithServerAndDefaultDescriptions(): void
    {
        $service = (new ApiService('Orders', 'orders'))->setBaseUrl('https://api.example.test');
        $service->addEndpoint(new ApiEndpoint('Ping', 'ping'));
        $exporter = new OpenApiExporter(new DocumentParser());

        $document = $this->decode($exporter->exportService($service));

        self::assertSame('Exported from API Studio', $document['info']['description']);
        self::assertSame([['url' => 'https://api.example.test']], $document['servers']);
        self::assertSame('ping', $document['paths']['/']['get']['operationId']);

        $workspace = $this->decode($exporter->exportWorkspace(new ApiWorkspace('Empty', 'empty')));
        self::assertSame('Exported from API Studio', $workspace['info']['description']);
        self::assertSame([], $workspace['paths']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
