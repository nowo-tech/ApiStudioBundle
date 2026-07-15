<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use PHPUnit\Framework\TestCase;

final class DocumentParserTest extends TestCase
{
    public function testParsesJsonDocument(): void
    {
        $parser = new DocumentParser();
        $data   = $parser->parse('{"services":[]}', 'collection.json');

        self::assertSame(['services' => []], $data);
    }

    public function testParsesYamlOpenApiDocument(): void
    {
        $parser = new DocumentParser();
        $data   = $parser->parse("openapi: 3.0.0\ninfo:\n  title: Demo", 'openapi.yaml');

        self::assertSame('3.0.0', $data['openapi']);
    }

    public function testRejectsEmptyDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocumentParser())->parse('   ');
    }

    public function testEncodeJsonAndYaml(): void
    {
        $parser = new DocumentParser();
        $data   = ['name' => 'demo'];

        self::assertStringContainsString('"name"', $parser->encodeJson($data));
        self::assertStringContainsString('name:', $parser->encodeYaml($data));
    }
}
