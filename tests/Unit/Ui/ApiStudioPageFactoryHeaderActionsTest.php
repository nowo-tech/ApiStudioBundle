<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Ui;

use Nowo\ApiStudioBundle\Tests\Support\ApiStudioPageFixtures;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function in_array;

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

    private function factory(string $headerActions): ApiStudioPageFactory
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters = []): string => '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters)));

        return new ApiStudioPageFactory($router, new ApiStudioPages(headerActions: $headerActions), foldersEnabled: true);
    }
}
