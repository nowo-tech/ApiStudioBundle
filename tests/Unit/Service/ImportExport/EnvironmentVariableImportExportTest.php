<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableImporter;
use PHPUnit\Framework\TestCase;

final class EnvironmentVariableImportExportTest extends TestCase
{
    public function testExportAndImportJsonRoundTrip(): void
    {
        $workspace   = new ApiWorkspace('Demo', 'demo');
        $environment = new ApiEnvironment('Sandbox', 'sandbox');
        $variable    = new ApiEnvironmentVariable('api_key', 'secret');
        $variable->setSecret(true);
        $environment->addVariable($variable);
        $workspace->addEnvironment($environment);

        $parser   = new DocumentParser();
        $exporter = new EnvironmentVariableExporter($parser);
        $json     = $exporter->render($environment, 'json');

        self::assertStringContainsString('"key": "{{api_key}}"', $json);

        $em     = $this->createMock(EntityManagerInterface::class);
        $target = new ApiEnvironment('Target', 'target');
        $workspace->addEnvironment($target);
        $importer = new EnvironmentVariableImporter($parser, $em);

        $result = $importer->importIntoEnvironment($target, $json, 'merge', 'vars.json');

        self::assertSame(1, $result->variablesCreated);
        self::assertCount(1, $target->getVariables());
        self::assertSame('api_key', $target->getVariables()->first()->getVariableKey());
    }

    public function testImportDotEnv(): void
    {
        $environment = new ApiEnvironment('Sandbox', 'sandbox');
        $em          = $this->createMock(EntityManagerInterface::class);
        $importer    = new EnvironmentVariableImporter(new DocumentParser(), $em);

        $result = $importer->importIntoEnvironment($environment, "API_KEY=test123\n# comment\nTOKEN=abc", 'merge', 'vars.env');

        self::assertSame(2, $result->variablesCreated);
    }
}
