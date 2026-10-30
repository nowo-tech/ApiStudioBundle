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

    public function testRejectsNonObjectJsonDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocumentParser())->parse('"just-a-string"', 'doc.json');
    }

    public function testEncodeJsonAndYaml(): void
    {
        $parser = new DocumentParser();
        $data   = ['name' => 'demo'];

        self::assertStringContainsString('"name"', $parser->encodeJson($data));
        self::assertStringContainsString('name:', $parser->encodeYaml($data));
    }

    public function testRejectsYamlAliasBomb(): void
    {
        // BW audit I5: a few hundred bytes of aliases expand into millions of values.
        $yaml = "openapi: 3.0.0\na: &a [x, x, x, x, x, x, x, x, x, x]\n";
        $prev = 'a';
        foreach (['b', 'c', 'd', 'e', 'f', 'g'] as $level) {
            $yaml .= $level . ': &' . $level . ' [' . implode(', ', array_fill(0, 10, '*' . $prev)) . "]\n";
            $prev = $level;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 100000 values');

        (new DocumentParser())->parse($yaml, 'bomb.yaml');
    }

    public function testAcceptsADocumentWithinTheBudget(): void
    {
        $data = (new DocumentParser())->parse('{"items": ' . json_encode(range(1, 1000)) . '}');

        self::assertCount(1000, $data['items']);
    }
}
