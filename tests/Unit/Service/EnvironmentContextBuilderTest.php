<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
        $production->addVariable(new ApiEnvironmentVariable('api_key', 'prod-key'));
        $production->addVariable(new ApiEnvironmentVariable('extra', 'only-prod'));
        $workspace->addEnvironment($production);

        $catalog = $this->builder->buildVariableCatalog($workspace);

        self::assertCount(2, $catalog);
        self::assertSame('api_key', $catalog[0]['key']);
        self::assertSame('extra', $catalog[1]['key']);
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
