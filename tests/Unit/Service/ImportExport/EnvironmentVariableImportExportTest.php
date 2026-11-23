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

use function array_filter;
use function array_values;

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
        $importedVariable = $target->getVariables()->first();
        self::assertInstanceOf(ApiEnvironmentVariable::class, $importedVariable);
        self::assertSame('api_key', $importedVariable->getVariableKey());
    }

    public function testImportDotEnv(): void
    {
        $environment = new ApiEnvironment('Sandbox', 'sandbox');
        $em          = $this->createMock(EntityManagerInterface::class);
        $importer    = new EnvironmentVariableImporter(new DocumentParser(), $em);

        $result = $importer->importIntoEnvironment($environment, "API_KEY=test123\n# comment\nTOKEN=abc", 'merge', 'vars.env');

        self::assertSame(2, $result->variablesCreated);
    }

    /**
     * 1.21.1: a workspace export whose default environment is new in the target workspace becomes the only default
     * (the target's own default is cleared), never a second one.
     */
    public function testAnImportedDefaultEnvironmentIsTheOnlyDefault(): void
    {
        $source     = new ApiWorkspace('Source', 'source');
        $production = (new ApiEnvironment('Production', 'production'))->setIsDefault(true);
        $source->addEnvironment($production);
        $parser = new DocumentParser();
        $json   = (new EnvironmentVariableExporter($parser))->renderWorkspace($source, 'json');

        $target = new ApiWorkspace('Target', 'target');
        $local  = (new ApiEnvironment('Local', 'local'))->setIsDefault(true);
        $target->addEnvironment($local);

        $result = (new EnvironmentVariableImporter($parser, $this->createMock(EntityManagerInterface::class)))
            ->importIntoWorkspace($target, $json, 'merge', 'environments.json');

        self::assertSame(1, $result->environmentsCreated);
        $defaults = array_values(array_filter($target->getEnvironments()->toArray(), static fn (ApiEnvironment $e): bool => $e->isDefault()));
        self::assertCount(1, $defaults);
        self::assertSame('production', $defaults[0]->getSlug());
        self::assertFalse($local->isDefault());
    }
}
