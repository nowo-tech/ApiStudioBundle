<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableImporter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * .env / YAML / workspace payloads of the environment variable import and export.
 */
final class EnvironmentVariableFormatsTest extends TestCase
{
    public function testDotEnvExportOfOneEnvironment(): void
    {
        [, $dev] = $this->workspace();

        self::assertSame(
            "# API Studio environment export\n# Environment: Dev\n# Service root\nBASE_URL='https://dev.example.test'\nAPI_KEY='two words'\nEMPTY=''\n",
            (new EnvironmentVariableExporter(new DocumentParser()))->render($dev, 'env'),
        );
    }

    public function testDotEnvExportOfWorkspaceListsEveryEnvironment(): void
    {
        [$workspace] = $this->workspace();

        self::assertSame(
            "# API Studio environment export\n\n# Dev\n# Service root\nBASE_URL='https://dev.example.test'\nAPI_KEY='two words'\nEMPTY=''\n\n# Prod\nBASE_URL='https://example.test'\n",
            (new EnvironmentVariableExporter(new DocumentParser()))->renderWorkspace($workspace, 'env'),
        );
    }

    public function testYamlAndJsonWorkspaceExportsRoundTripIntoAnotherWorkspace(): void
    {
        [$workspace] = $this->workspace();
        $exporter    = new EnvironmentVariableExporter(new DocumentParser());

        $yaml = $exporter->renderWorkspace($workspace, 'yaml');
        self::assertStringContainsString('format: api-studio-workspace-environments', $yaml);
        self::assertStringContainsString('format: api-studio-environment', $exporter->render($workspace->getEnvironments()->first() ?: new ApiEnvironment('x', 'x'), 'yaml'));

        $target   = new ApiWorkspace('Copy', 'copy');
        $existing = new ApiEnvironment('Dev', 'dev');
        $existing->addVariable(new ApiEnvironmentVariable('base_url', 'old'));
        $target->addEnvironment($existing);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist'); // only "prod" is new

        $result = (new EnvironmentVariableImporter(new DocumentParser(), $em))
            ->importIntoWorkspace($target, $exporter->renderWorkspace($workspace, 'json'), 'merge', 'envs.json');

        self::assertSame(1, $result->environmentsCreated);
        self::assertSame(3, $result->variablesCreated);
        self::assertSame(1, $result->variablesUpdated);
        self::assertSame('https://dev.example.test', $existing->getVariableMap()['base_url']);
        $prod = $target->getEnvironments()->last();
        self::assertInstanceOf(ApiEnvironment::class, $prod);
        self::assertSame('prod', $prod->getSlug());
        self::assertFalse($prod->isDefault());
        $apiKey = $existing->getVariables()->filter(static fn (ApiEnvironmentVariable $v): bool => $v->getVariableKey() === 'api-key')->first();
        self::assertInstanceOf(ApiEnvironmentVariable::class, $apiKey);
        self::assertTrue($apiKey->isSecret());
        self::assertSame('Service root', $existing->getVariables()->first() ? $existing->getVariables()->first()->getDescription() : null);
    }

    public function testWorkspacePayloadIntoSingleEnvironmentAndReplaceMode(): void
    {
        [$workspace, $dev] = $this->workspace();
        $payload           = json_encode([
            'format'       => 'api-studio-workspace-environments',
            'environments' => [
                'not-an-environment',
                ['environment' => ['name' => 'Other'], 'variables' => [['key' => 'region', 'value' => 'eu'], 'bad', ['key' => '!!']]],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = (new EnvironmentVariableImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->importIntoEnvironment($dev, $payload, 'replace');

        self::assertSame(1, $result->variablesCreated);
        self::assertSame(['region' => 'eu'], $dev->getVariableMap());
        self::assertCount(2, $workspace->getEnvironments());
    }

    public function testWorkspacePayloadNeedsAWorkspace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workspace is required for multi-environment import.');

        (new EnvironmentVariableImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->importIntoEnvironment(new ApiEnvironment('Loose', 'loose'), '{"format": "api-studio-workspace-environments", "environments": []}', 'merge');
    }

    public function testWorkspacePayloadCreatesEnvironmentsFromNameOrDefaultSlug(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $payload   = json_encode(['format' => 'api-studio-workspace-environments', 'environments' => [
            ['environment' => ['name' => 'Staging Area'], 'variables' => []],
            ['variables' => [['key' => 'x', 'value' => '1', 'description' => 'Ex']]],
        ]], JSON_THROW_ON_ERROR);

        $result = (new EnvironmentVariableImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class)))
            ->importIntoWorkspace($workspace, $payload, 'merge');

        self::assertSame(2, $result->environmentsCreated);
        $slugs = array_map(static fn (ApiEnvironment $e): string => $e->getSlug() . ':' . $e->getName(), $workspace->getEnvironments()->toArray());
        self::assertSame(['staging_area:Staging Area', 'imported:Imported'], $slugs);
        self::assertFalse($workspace->getEnvironments()->first() ? $workspace->getEnvironments()->first()->isDefault() : true);
    }

    public function testSingleEnvironmentPayloadIntoWorkspaceUsesDefaultOrCreatesOne(): void
    {
        $importer = new EnvironmentVariableImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class));
        $payload  = '{"format": "api-studio-environment", "variables": [{"key": "{{token}}", "value": "REDACTED-TEST"}]}';

        $empty  = new ApiWorkspace('Empty', 'empty');
        $result = $importer->importIntoWorkspace($empty, $payload, 'merge', 'vars.json');
        self::assertSame(1, $result->environmentsCreated);
        $created = $empty->getEnvironments()->first();
        self::assertInstanceOf(ApiEnvironment::class, $created);
        self::assertSame('imported', $created->getSlug());
        self::assertTrue($created->isDefault());
        self::assertSame(['token' => 'REDACTED-TEST'], $created->getVariableMap());

        [$workspace, , $prod] = $this->workspace();
        $prod->setIsDefault(true);
        $importer->importIntoWorkspace($workspace, $payload, 'merge');
        self::assertSame('REDACTED-TEST', $prod->getVariableMap()['token']);
    }

    public function testDotEnvIntoWorkspace(): void
    {
        $importer = new EnvironmentVariableImporter(new DocumentParser(), $this->createStub(EntityManagerInterface::class));
        $dotEnv   = "# exported\n\nAPI-KEY='abc'\nnot a pair\n!!=bad\nBASE_URL=\"https://x.test\"\r\nAPI_KEY=def";

        $empty  = new ApiWorkspace('Empty', 'empty');
        $result = $importer->importIntoWorkspace($empty, $dotEnv, 'merge');
        self::assertSame(1, $result->environmentsCreated);
        self::assertSame(2, $result->variablesCreated);
        self::assertSame(1, $result->variablesUpdated);
        $environment = $empty->getEnvironments()->first();
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        self::assertSame(['api_key' => 'def', 'base_url' => 'https://x.test'], $environment->getVariableMap());

        [$workspace, $dev] = $this->workspace();
        (new ReflectionProperty(ApiEnvironment::class, 'id'))->setValue($dev, 3);
        $replaced = $importer->importIntoWorkspace($workspace, "ONLY=1\n", 'replace', 'vars.env');
        self::assertSame(0, $replaced->environmentsCreated);
        self::assertSame(['only' => '1'], $dev->getVariableMap());
    }

    /**
     * @return array{ApiWorkspace, ApiEnvironment, ApiEnvironment}
     */
    private function workspace(): array
    {
        $workspace = new ApiWorkspace('Shop', 'shop');
        $dev       = new ApiEnvironment('Dev', 'dev');
        $dev->addVariable((new ApiEnvironmentVariable('base_url', 'https://dev.example.test'))->setDescription('Service root'));
        $dev->addVariable((new ApiEnvironmentVariable('api-key', 'two words'))->setSecret(true)->setDescription(''));
        $dev->addVariable(new ApiEnvironmentVariable('empty'));
        $prod = (new ApiEnvironment('Prod', 'prod'))->setIsDefault(false);
        $prod->addVariable(new ApiEnvironmentVariable('base_url', 'https://example.test'));
        $workspace->addEnvironment($dev)->addEnvironment($prod);

        return [$workspace, $dev, $prod];
    }
}
