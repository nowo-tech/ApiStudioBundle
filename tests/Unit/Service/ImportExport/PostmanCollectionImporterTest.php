<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\PostmanCollectionImporter;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class PostmanCollectionImporterTest extends TestCase
{
    public function testImportsNestedRequestsAndVariablesIntoNewService(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $em        = $this->createMock(EntityManagerInterface::class);
        // environment + 4 endpoints + service
        $em->expects(self::exactly(6))->method('persist');

        $result = (new PostmanCollectionImporter(new DocumentParser(), $em))->import($workspace, $this->collection(), 'shop.postman_collection.json');

        self::assertSame(1, $result->servicesCreated);
        self::assertSame(4, $result->endpointsCreated);
        self::assertSame(1, $result->environmentsCreated);
        self::assertSame(2, $result->variablesCreated);
        self::assertSame(0, $result->variablesUpdated);
        self::assertSame(['Imported Postman collection'], $result->messages);

        $environment = $workspace->getEnvironments()->first();
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        self::assertSame('Postman', $environment->getName());
        self::assertTrue($environment->isDefault());
        self::assertSame(['baseUrl' => 'https://shop.example.test', 'token' => 'REDACTED-TEST'], $environment->getVariableMap());

        $service = $workspace->getServices()->first();
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame('Shop API', $service->getName());
        self::assertSame('{{baseUrl}}', $service->getBaseUrl());

        $endpoints = [];
        foreach ($service->getEndpoints() as $endpoint) {
            $endpoints[$endpoint->getName()] = $endpoint;
        }
        self::assertSame(['List orders', 'Create order', 'Weird', 'Structured'], array_keys($endpoints));

        $list = $endpoints['List orders'];
        self::assertSame(HttpMethod::Get, $list->getMethod());
        self::assertSame('/orders', $list->getPath());
        self::assertSame(['page' => '2', 'tags' => ''], $list->getQueryParams());
        self::assertSame(['Accept' => 'application/json'], $list->getHeaders());

        $create = $endpoints['Create order'];
        self::assertSame(HttpMethod::Post, $create->getMethod());
        self::assertSame('{"sku":"A1"}', $create->getRequestBodyTemplate());
        self::assertSame('json', $create->getContentType());

        $weird = $endpoints['Weird'];
        self::assertSame(HttpMethod::Get, $weird->getMethod(), 'unknown methods fall back to GET');
        self::assertSame('/', $weird->getPath());
        self::assertNull($weird->getRequestBodyTemplate());

        $structured = $endpoints['Structured'];
        self::assertSame('/v1/items/{{id}}', $structured->getPath());
        self::assertSame(['q' => 'shoe'], $structured->getQueryParams());
    }

    public function testImportsIntoTargetServiceAndUpdatesExistingVariables(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $other     = new ApiEnvironment('Other', 'other');
        $default   = (new ApiEnvironment('Dev', 'dev'))->setIsDefault(true);
        $default->addVariable(new ApiEnvironmentVariable('token', 'old'));
        $workspace->addEnvironment($other)->addEnvironment($default);
        $target = new ApiService('Existing', 'existing');
        $target->addEndpoint(new ApiEndpoint('List orders', 'list_orders'));
        $workspace->addService($target);

        $result = (new PostmanCollectionImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->import($workspace, $this->collection(), null, true, $target);

        self::assertSame(0, $result->servicesCreated);
        self::assertSame(4, $result->endpointsCreated);
        self::assertSame(0, $result->environmentsCreated);
        self::assertSame(1, $result->variablesCreated);
        self::assertSame(1, $result->variablesUpdated);
        self::assertSame('REDACTED-TEST', $default->getVariableMap()['token']);
        self::assertSame([], $other->getVariableMap());
        self::assertCount(5, $target->getEndpoints());
        self::assertSame('list_orders_2', $target->getEndpoints()->get(1)?->getSlug());
    }

    public function testFirstEnvironmentIsUsedWhenNoneIsDefaultAndVariablesCanBeSkipped(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $first     = new ApiEnvironment('First', 'first');
        $workspace->addEnvironment($first);
        $importer = new PostmanCollectionImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class));

        $result = $importer->import($workspace, $this->collection());
        self::assertSame(2, $result->variablesCreated);
        self::assertCount(2, $first->getVariables());

        $skipped = $importer->import($workspace, '{"item": [], "variable": [{"key": "x", "value": "1"}]}', null, false);
        self::assertSame(0, $skipped->variablesCreated);
        $service = $workspace->getServices()->last();
        self::assertInstanceOf(ApiService::class, $service);
        self::assertSame('{{base_url}}', $service->getBaseUrl());
        self::assertSame('Postman Collection', $service->getName());
        self::assertCount(2, $first->getVariables());
    }

    public function testRejectsDocumentsThatAreNotCollections(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Postman collection format.');

        (new PostmanCollectionImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->import(new ApiWorkspace('Demo', 'demo'), '{"info": {"name": "x"}}');
    }

    private function collection(): string
    {
        return json_encode([
            'info'     => ['name' => 'Shop API', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'variable' => [
                'not-a-variable',
                ['key' => 'baseUrl', 'value' => 'https://shop.example.test'],
                ['key' => 'token', 'value' => 'REDACTED-TEST'],
                ['key' => '   '],
            ],
            'item' => [
                'not-an-item',
                ['name' => 'No request'],
                ['name' => 'Orders', 'item' => [
                    ['name' => 'List orders', 'request' => [
                        'method' => 'get',
                        'url'    => ['raw' => 'https://shop.example.test/orders?page=2&tags[]=a&0=zero'],
                        'header' => [
                            ['key' => 'Accept', 'value' => 'application/json'],
                            ['key' => 'X-Disabled', 'value' => '1', 'enabled' => false],
                            ['key' => '', 'value' => 'nameless'],
                            'bad-header',
                        ],
                    ]],
                    ['name' => 'Create order', 'request' => [
                        'method' => 'POST',
                        'url'    => 'https://shop.example.test/orders',
                        'body'   => ['mode' => 'raw', 'raw' => '{"sku":"A1"}', 'options' => ['raw' => ['language' => 'json']]],
                    ]],
                ]],
                ['name' => 'Weird', 'request' => ['method' => 'BREW', 'url' => 42, 'body' => ['mode' => 'formdata']]],
                ['name' => 'Structured', 'request' => [
                    'url' => [
                        'path'  => ['v1', 'items', '{{id}}'],
                        'query' => [
                            ['key' => 'q', 'value' => 'shoe'],
                            ['key' => 'off', 'value' => '1', 'enabled' => false],
                            ['key' => '', 'value' => 'x'],
                            'bad',
                        ],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
