<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_intersect_key;

final class EnvironmentContextBuilderTest extends TestCase
{
    private EnvironmentContextBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new EnvironmentContextBuilder();
    }

    public function testBuildVariableCatalogUnionsKeys(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');

        $sandbox = new ApiEnvironment('Sandbox', 'sandbox');
        $sandbox->addVariable(new ApiEnvironmentVariable('api_key', 'sandbox-key'));
        $workspace->addEnvironment($sandbox);

        $production = new ApiEnvironment('Production', 'production');
        $unreadable = (new ApiEnvironmentVariable('api_key', 'nowo_as_enc_v1:payload'))->setSecret(true);
        $unreadable->markUnreadable();
        $production->addVariable($unreadable);
        $production->addVariable(new ApiEnvironmentVariable('extra', 'only-prod'));
        $workspace->addEnvironment($production);

        $catalog = $this->builder->buildVariableCatalog($workspace);

        self::assertCount(2, $catalog);
        self::assertSame('api_key', $catalog[0]['key']);
        self::assertSame('extra', $catalog[1]['key']);
        self::assertTrue($catalog[0]['needs_reentry'], 'Unreadable in one environment: flagged in the catalogue.');
        self::assertFalse($catalog[1]['needs_reentry']);
        self::assertSame([], self::readable($production));
    }

    /** @return array<string, string> */
    private static function readable(ApiEnvironment $environment): array
    {
        return array_intersect_key($environment->getVariableMap(), ['api_key' => true]);
    }

    public function testBuildMapsSkipsUnsavedEnvironmentsAndResolvesMaps(): void
    {
        $workspace = new ApiWorkspace('Demo', 'demo');
        $saved     = (new ApiEnvironment('Dev', 'dev'))->setIsDefault(true);
        $saved->addVariable(new ApiEnvironmentVariable('base_url', 'https://dev.example.test'));
        (new ReflectionProperty(ApiEnvironment::class, 'id'))->setValue($saved, 7);
        $workspace->addEnvironment($saved);
        $workspace->addEnvironment(new ApiEnvironment('Unsaved', 'unsaved'));

        self::assertSame([
            7 => ['name' => 'Dev', 'is_default' => true, 'variables' => ['base_url' => 'https://dev.example.test']],
        ], $this->builder->buildMaps($workspace));
        self::assertSame(['base_url' => 'https://dev.example.test'], $this->builder->resolveMapForEnvironment($saved));
        self::assertSame([], $this->builder->resolveMapForEnvironment(null));
    }
}
