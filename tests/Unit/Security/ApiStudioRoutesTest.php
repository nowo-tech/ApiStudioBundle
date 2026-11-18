<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioRouteActions;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiStudioRoutesTest extends TestCase
{
    public function testBundleRoutesAndDeclaredHostRoutesAreStudioRoutes(): void
    {
        $routes = new ApiStudioRoutes(['app_share' => 'studio.open', 'app_import' => ApiStudioAction::WorkspaceCreate]);

        self::assertTrue($routes->isStudioRoute('nowo_api_studio_dashboard'));
        self::assertTrue($routes->isStudioRoute('nowo_api_studio_not_mapped'), 'the prefix: mapped or 404 later');
        self::assertTrue($routes->isStudioRoute('app_share'));
        self::assertFalse($routes->isStudioRoute('app_home'));
        self::assertFalse($routes->isStudioRoute(null));
        self::assertFalse($routes->isStudioRoute(12));

        self::assertSame(ApiStudioAction::StudioOpen, $routes->actionFor('app_share'));
        self::assertSame(ApiStudioAction::WorkspaceCreate, $routes->actionFor('app_import'));
        self::assertSame(ApiStudioAction::WorkspaceManage, $routes->actionFor('nowo_api_studio_workspace_edit'));
        self::assertNull($routes->actionFor('nowo_api_studio_not_mapped'));
        self::assertNull($routes->actionFor('app_home'));
        self::assertSame(['app_share' => ApiStudioAction::StudioOpen, 'app_import' => ApiStudioAction::WorkspaceCreate], $routes->hostRoutes());
    }

    public function testEveryBundleRouteHasItsMappedAction(): void
    {
        $routes = new ApiStudioRoutes();
        foreach (ApiStudioRouteActions::MAP as $route => $action) {
            self::assertTrue($routes->isStudioRoute($route), $route);
            self::assertSame($action, $routes->actionFor($route), $route);
        }
        self::assertSame([], $routes->hostRoutes());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidHostRoutes(): iterable
    {
        yield 'bundle route' => [['nowo_api_studio_dashboard' => 'studio.open'], 'use a route of the host'];
        yield 'empty name' => [['' => 'studio.open'], 'use a route of the host'];
        yield 'workspace action' => [['app_share' => 'workspace.manage'], 'must be "studio.open" or "workspace.create"'];
        yield 'unknown action' => [['app_share' => 'studio.fly'], 'must be "studio.open" or "workspace.create"'];
    }

    /**
     * @param array<string, string> $hostRoutes
     */
    #[DataProvider('invalidHostRoutes')]
    public function testInvalidHostRoutesAreRefused(array $hostRoutes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ApiStudioRoutes($hostRoutes);
    }
}
