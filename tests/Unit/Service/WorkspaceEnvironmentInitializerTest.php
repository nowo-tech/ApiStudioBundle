<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\WorkspaceEnvironmentInitializer;
use PHPUnit\Framework\TestCase;

final class WorkspaceEnvironmentInitializerTest extends TestCase
{
    public function testSeedsConfiguredEnvironmentsWithFirstAsDefault(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');

        (new WorkspaceEnvironmentInitializer(['dev', 'Staging EU', '', ' dev ', 'prod']))->initialize($workspace);

        $environments = $workspace->getEnvironments()->toArray();
        self::assertSame(['dev', 'Staging EU', 'prod'], array_map(static fn (ApiEnvironment $e): string => $e->getName(), $environments));
        self::assertSame(['dev', 'staging_eu', 'prod'], array_map(static fn (ApiEnvironment $e): string => $e->getSlug(), $environments));
        self::assertSame([true, false, false], array_map(static fn (ApiEnvironment $e): bool => $e->isDefault(), $environments));
        self::assertSame($workspace, $environments[0]->getWorkspace());
    }

    public function testEmptyListDisablesSeeding(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');

        (new WorkspaceEnvironmentInitializer([]))->initialize($workspace);

        self::assertCount(0, $workspace->getEnvironments());
    }

    public function testDoesNotTouchWorkspaceThatAlreadyHasEnvironments(): void
    {
        $workspace = new ApiWorkspace('Mine', 'mine');
        $workspace->addEnvironment(new ApiEnvironment('Custom', 'custom'));

        (new WorkspaceEnvironmentInitializer(['dev', 'prod']))->initialize($workspace);

        self::assertCount(1, $workspace->getEnvironments());
    }
}
