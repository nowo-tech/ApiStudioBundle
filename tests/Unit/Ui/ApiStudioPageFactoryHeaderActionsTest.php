<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Ui;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\ApiStudioPageFixtures;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function in_array;
use function is_string;

/**
 * `nowo_api_studio.ui.header_actions` (1.23): `header` keeps the toolbar buttons, `page_actions` renders every create
 * action as a secondary View\Action of the UiKit split header (the page-actions menu).
 */
final class ApiStudioPageFactoryHeaderActionsTest extends TestCase
{
    public function testHeaderModeKeepsPrimaryToolbarButtons(): void
    {
        foreach ($this->headers($this->factory(ApiStudioPages::HEADER_ACTIONS_HEADER)) as $name => $header) {
            self::assertSame('toolbar', $header->actionsLayout, $name);
            foreach ($this->creates($header) as $action) {
                self::assertSame('primary', $action->resolvedVariant(), $name);
            }
        }
    }

    public function testPageActionsModeUsesTheSplitHeaderWithSecondaryEntries(): void
    {
        $headers = $this->headers($this->factory(ApiStudioPages::HEADER_ACTIONS_PAGE_ACTIONS));

        self::assertCount(9, $headers);
        foreach ($headers as $name => $header) {
            self::assertSame('split', $header->actionsLayout, $name);
            if (!in_array($name, ['environment', 'request console'], true)) {
                self::assertNotSame([], $this->creates($header), $name . ' has a create action');
            }
            foreach ($this->creates($header) as $action) {
                self::assertSame('secondary', $action->resolvedVariant(), $name);
            }
        }
    }

    public function testTheEmptyStateKeepsItsPrimaryActionInBothModes(): void
    {
        foreach ([ApiStudioPages::HEADER_ACTIONS_HEADER, ApiStudioPages::HEADER_ACTIONS_PAGE_ACTIONS] as $mode) {
            $factory = $this->factory($mode);
            $page    = $factory->workspaceIndex([], new Pagination(1, 50, 0, 0, 'nowo_api_studio_workspace_index'));

            self::assertSame('primary', $page->empty?->action?->resolvedVariant(), $mode);
        }
    }

    /**
     * ZAY-I2: the gating of the create actions is the same in both modes: a denied action is not in the page-actions menu.
     */
    public function testADeniedActionIsMissingInPageActionsMode(): void
    {
        $workspace = ApiStudioPageFixtures::workspace();
        $service   = ApiStudioPageFixtures::service($workspace);

        foreach ([ApiStudioPages::HEADER_ACTIONS_HEADER, ApiStudioPages::HEADER_ACTIONS_PAGE_ACTIONS] as $mode) {
            $denied  = $this->factory($mode, [ApiStudioAction::WorkspaceEdit, ApiStudioAction::WorkspaceCreate]);
            $allowed = $this->factory($mode);

            self::assertContains('api-studio-new-service', $this->testIds($allowed->workspaceShow($workspace)->header), $mode);
            self::assertNotContains('api-studio-new-service', $this->testIds($denied->workspaceShow($workspace)->header), $mode . ': New service');
            self::assertNotContains('api-studio-new-endpoint', $this->testIds($denied->serviceShow($workspace, $service)->header), $mode . ': New endpoint');
            self::assertNotContains('api-studio-new-workspace', $this->testIds($denied->dashboard(1, 1, 1)->header), $mode . ': New workspace');
            self::assertContains('api-studio-new-workspace', $this->testIds($allowed->dashboard(1, 1, 1)->header), $mode);
        }
    }

    /**
     * @return list<string>
     */
    private function testIds(PageHeader $header): array
    {
        $ids = [];
        foreach ($header->actions as $action) {
            $id = $action->attributes['data-testid'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, PageHeader>
     */
    private function headers(ApiStudioPageFactory $factory): array
    {
        $workspace   = ApiStudioPageFixtures::workspace();
        $service     = ApiStudioPageFixtures::service($workspace);
        $endpoint    = ApiStudioPageFixtures::endpoint($service);
        $environment = ApiStudioPageFixtures::environment($workspace);
        $pagination  = new Pagination(1, 50, 1, 1, 'nowo_api_studio_workspace_index');

        return [
            'dashboard'        => $factory->dashboard(1, 1, 1)->header,
            'workspace list'   => $factory->workspaceIndex([$workspace], $pagination)->header,
            'workspace'        => $factory->workspaceShow($workspace)->header,
            'service list'     => $factory->serviceIndex($workspace, [$service])->header,
            'service'          => $factory->serviceShow($workspace, $service)->header,
            'endpoint list'    => $factory->endpointIndex($workspace, $service, [$endpoint])->header,
            'environment list' => $factory->environmentIndex($workspace, [$environment])->header,
            'environment'      => $factory->environmentShow($workspace, $environment)->header,
            'request console'  => $factory->endpointShow($workspace, $service, $endpoint)->header,
        ];
    }

    /**
     * The create-kind actions of a header (a header without one, such as the request console, has none to check).
     *
     * @return list<Action>
     */
    private function creates(PageHeader $header): array
    {
        $creates = [];
        foreach ($header->actions as $action) {
            if ($action->kind === 'create') {
                $creates[] = $action;
            }
        }

        return $creates;
    }

    /**
     * @param list<ApiStudioAction> $denied
     */
    private function factory(string $headerActions, array $denied = []): ApiStudioPageFactory
    {
        $authorization = $denied === [] ? null : new ApiStudioAuthorization(new class($denied) implements ApiStudioPermissionCheckerInterface {
            /** @param list<ApiStudioAction> $denied */
            public function __construct(private readonly array $denied)
            {
            }

            public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
            {
                return !in_array($action, $this->denied, true);
            }

            public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
            {
                return null;
            }
        });

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters)));

        return new ApiStudioPageFactory($router, new ApiStudioPages(headerActions: $headerActions), $authorization, true);
    }
}
