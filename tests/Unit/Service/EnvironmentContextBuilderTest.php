<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use PHPUnit\Framework\TestCase;

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
}
