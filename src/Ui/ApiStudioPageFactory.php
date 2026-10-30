<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Ui;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\KpiTile;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function strtoupper;

/**
 * Builds the UiKit view models (REQ-UI-003) of every Api Studio page: page headers with their actions and
 * breadcrumbs, list rows (plain arrays), detail sections, form pages with the delete zone behind the UiKit confirm
 * contract. Labels are {@see ApiStudioPages::label()} keys of the NowoApiStudioBundle domain; the scaffolds translate
 * them. Stateless (only the URL generator and the configuration): safe in worker mode.
 */
final readonly class ApiStudioPageFactory
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ApiStudioPages $pages = new ApiStudioPages(),
    ) {
    }

    /**
     * Controller variables plus the shared page variables ({@see ApiStudioPages::context()}).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    public function dashboard(int $workspaces, int $services, int $endpoints): DashboardPage
    {
        return new DashboardPage(
            header: new PageHeader(
                ApiStudioPages::label('page.dashboard'),
                ApiStudioPages::label('dashboard.subtitle'),
                [$this->newWorkspaceAction()],
            ),
            kpis: [
                new KpiTile(ApiStudioPages::label('dashboard.stat.workspaces'), $workspaces, href: $this->url('nowo_api_studio_workspace_index')),
                new KpiTile(ApiStudioPages::label('dashboard.stat.services'), $services),
                new KpiTile(ApiStudioPages::label('dashboard.stat.endpoints'), $endpoints),
            ],
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-dashboard'],
        );
    }

    /**
     * @param iterable<ApiWorkspace> $workspaces
     */
    public function workspaceIndex(iterable $workspaces, Pagination $pagination): ListPage
    {
        $rows = [];
        foreach ($workspaces as $workspace) {
            $rows[] = [
                'id'   => $workspace->getId(),
                'name' => $workspace->getName(),
                'slug' => $workspace->getSlug(),
                'href' => $this->url('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]),
                'edit' => $this->url('nowo_api_studio_workspace_edit', ['id' => $workspace->getId()]),
            ];
        }

        return new ListPage(
            header: new PageHeader(ApiStudioPages::label('page.workspaces'), null, [$this->newWorkspaceAction()], [$this->homeCrumb(), new Crumb(ApiStudioPages::label('page.workspaces'))]),
            columns: [
                new Column('name', ApiStudioPages::label('field.name'), required: true),
                new Column('slug', ApiStudioPages::label('field.slug'), priority: 2),
            ],
            rows: $rows,
            rowActions: static fn (array $row): array => [
                Action::view((string) $row['href'], ApiStudioPages::label('action.open')),
                Action::edit((string) $row['edit'], ApiStudioPages::label('action.edit')),
            ],
            pagination: $pagination,
            empty: new EmptyState(null, ApiStudioPages::label('empty.workspaces'), $this->newWorkspaceAction()),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-workspaces'],
            actionsLabel: ApiStudioPages::label('field.actions'),
        );
    }

    public function workspaceShow(ApiWorkspace $workspace): ShowPage
    {
        $id = $workspace->getId();

        return new ShowPage(
            header: new PageHeader(
                $workspace->getName(),
                $workspace->getDescription(),
                [
                    new Action('create', ApiStudioPages::label('action.new_service'), $this->url('nowo_api_studio_service_new', ['workspaceId' => $id]), variant: 'primary', attributes: ['data-testid' => 'api-studio-new-service']),
                    new Action('create', ApiStudioPages::label('action.new_environment'), $this->url('nowo_api_studio_environment_new', ['workspaceId' => $id])),
                    new Action('custom', ApiStudioPages::label('section.environments'), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $id])),
                    new Action('import', ApiStudioPages::label('import_export.hub_title'), $this->url('nowo_api_studio_io_hub', ['workspaceId' => $id])),
                    Action::edit($this->url('nowo_api_studio_workspace_edit', ['id' => $id]), ApiStudioPages::label('action.edit')),
                ],
                $this->workspaceCrumbs($workspace, false),
            ),
            sections: [
                new DetailSection(ApiStudioPages::label('section.details'), [
                    new DetailItem(ApiStudioPages::label('field.slug'), $workspace->getSlug()),
                    new DetailItem(ApiStudioPages::label('section.services'), $workspace->getServices()->count()),
                    new DetailItem(ApiStudioPages::label('section.environments'), $workspace->getEnvironments()->count(), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $id])),
                ], 'api-studio-workspace-details'),
            ],
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-workspace'],
        );
    }

    /**
     * Create / edit form; the delete zone posts the CSRF-only form behind the UiKit confirm dialog.
     */
    public function workspaceForm(?ApiWorkspace $workspace, FormView $form, ?FormView $deleteForm = null): FormPage
    {
        $back = $workspace instanceof ApiWorkspace
            ? $this->url('nowo_api_studio_workspace_show', ['id' => $workspace->getId()])
            : $this->url('nowo_api_studio_workspace_index');
        $title  = ApiStudioPages::label($workspace instanceof ApiWorkspace ? 'page.edit_workspace' : 'page.new_workspace');
        $crumbs = $workspace instanceof ApiWorkspace ? [...$this->workspaceCrumbs($workspace), new Crumb($title)] : [$this->homeCrumb(), new Crumb(ApiStudioPages::label('page.workspaces'), $this->url('nowo_api_studio_workspace_index')), new Crumb($title)];

        return $this->formPage(
            $title,
            $workspace?->getName(),
            $crumbs,
            $back,
            $form,
            $workspace instanceof ApiWorkspace && $deleteForm instanceof FormView
                ? $this->deleteAction($this->url('nowo_api_studio_workspace_delete', ['id' => $workspace->getId()]), $deleteForm, 'api-studio-workspace-delete')
                : null,
            'api-studio-workspace-form',
        );
    }

    /**
     * @param iterable<ApiService> $services
     */
    public function serviceIndex(ApiWorkspace $workspace, iterable $services): ListPage
    {
        $wid  = $workspace->getId();
        $rows = [];
        foreach ($services as $service) {
            $params = ['workspaceId' => $wid, 'id' => $service->getId()];
            $rows[] = [
                'id'        => $service->getId(),
                'name'      => $service->getName(),
                'protocol'  => strtoupper($service->getProtocol()->value),
                'base_url'  => $service->getBaseUrl(),
                'endpoints' => $service->getEndpoints()->count(),
                'href'      => $this->url('nowo_api_studio_service_show', $params),
                'edit'      => $this->url('nowo_api_studio_service_edit', $params),
                'list'      => $this->url('nowo_api_studio_endpoint_index', ['workspaceId' => $wid, 'serviceId' => $service->getId()]),
            ];
        }

        return new ListPage(
            header: new PageHeader(
                ApiStudioPages::label('section.services'),
                ApiStudioPages::label('service.collections_hint'),
                [$this->newServiceAction($workspace)],
                [...$this->workspaceCrumbs($workspace), new Crumb(ApiStudioPages::label('section.services'))],
            ),
            columns: [
                new Column('name', ApiStudioPages::label('field.name'), required: true),
                new Column('protocol', ApiStudioPages::label('field.protocol')),
                new Column('base_url', ApiStudioPages::label('field.base_url'), priority: 2),
                new Column('endpoints', ApiStudioPages::label('section.endpoints'), align: 'end', priority: 2),
            ],
            rows: $rows,
            rowActions: static fn (array $row): array => [
                Action::view((string) $row['href'], ApiStudioPages::label('action.open_service')),
                new Action('view', ApiStudioPages::label('action.view_endpoints'), (string) $row['list']),
                Action::edit((string) $row['edit'], ApiStudioPages::label('action.edit')),
            ],
            empty: new EmptyState(null, ApiStudioPages::label('empty.services'), $this->newServiceAction($workspace)),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-services'],
            actionsLabel: ApiStudioPages::label('field.actions'),
        );
    }

    public function serviceShow(ApiWorkspace $workspace, ApiService $service): ShowPage
    {
        $wid = $workspace->getId();

        return new ShowPage(
            header: new PageHeader(
                $service->getName(),
                $service->getDescription(),
                [
                    new Action('create', ApiStudioPages::label('action.new_endpoint'), $this->url('nowo_api_studio_endpoint_new', ['workspaceId' => $wid, 'serviceId' => $service->getId()]), variant: 'primary', attributes: ['data-testid' => 'api-studio-new-endpoint']),
                    new Action('custom', ApiStudioPages::label('section.environments'), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $wid])),
                    Action::edit($this->url('nowo_api_studio_service_edit', ['workspaceId' => $wid, 'id' => $service->getId()]), ApiStudioPages::label('action.edit')),
                ],
                [...$this->workspaceCrumbs($workspace), new Crumb($service->getName())],
            ),
            sections: [
                new DetailSection(ApiStudioPages::label('section.details'), [
                    new DetailItem(ApiStudioPages::label('field.protocol'), strtoupper($service->getProtocol()->value)),
                    new DetailItem(ApiStudioPages::label('field.base_url'), $service->getBaseUrl()),
                    new DetailItem(ApiStudioPages::label('field.auth_type'), $service->getAuthType()->value),
                    new DetailItem(ApiStudioPages::label('section.endpoints'), $service->getEndpoints()->count(), $this->url('nowo_api_studio_endpoint_index', ['workspaceId' => $wid, 'serviceId' => $service->getId()])),
                ], 'api-studio-service-details'),
            ],
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-service'],
        );
    }

    /**
     * The delete form carries the service name (`confirmation`): the UiKit confirm dialog asks the user to type it.
     */
    public function serviceForm(ApiWorkspace $workspace, ?ApiService $service, FormView $form, ?FormView $deleteForm = null): FormPage
    {
        $wid  = $workspace->getId();
        $back = $service instanceof ApiService
            ? $this->url('nowo_api_studio_service_show', ['workspaceId' => $wid, 'id' => $service->getId()])
            : $this->url('nowo_api_studio_workspace_show', ['id' => $wid]);
        $title  = ApiStudioPages::label($service instanceof ApiService ? 'page.edit_service' : 'page.new_service');
        $crumbs = [...$this->workspaceCrumbs($workspace)];
        if ($service instanceof ApiService) {
            $crumbs[] = new Crumb($service->getName(), $back);
        }
        $crumbs[] = new Crumb($title);

        $delete = null;
        if ($service instanceof ApiService && $deleteForm instanceof FormView) {
            $delete = new Action(
                'delete',
                ApiStudioPages::label('action.delete'),
                $this->url('nowo_api_studio_service_delete', ['workspaceId' => $wid, 'id' => $service->getId()]),
                method: 'POST',
                confirm: new Confirm(
                    ApiStudioPages::label('service.delete_title'),
                    ApiStudioPages::label('service.delete_warning', ['%name%' => $service->getName()]),
                    ApiStudioPages::label('action.delete'),
                    cancelLabel: ApiStudioPages::label('action.cancel'),
                    expectedValue: $service->getName(),
                    expectedLabel: ApiStudioPages::label('service.delete_confirm_label', ['%name%' => $service->getName()]),
                ),
                attributes: ['data-testid' => 'api-studio-service-delete'],
                form: $deleteForm,
            );
        }

        return $this->formPage($title, $workspace->getName(), $crumbs, $back, $form, $delete, 'api-studio-service-form');
    }

    /**
     * @param iterable<ApiEndpoint> $endpoints
     */
    public function endpointIndex(ApiWorkspace $workspace, ApiService $service, iterable $endpoints): ListPage
    {
        $base = ['workspaceId' => $workspace->getId(), 'serviceId' => $service->getId()];
        $rows = [];
        foreach ($endpoints as $endpoint) {
            $rows[] = [
                'id'       => $endpoint->getId(),
                'method'   => $endpoint->getMethod()->value,
                'name'     => $endpoint->getName(),
                'path'     => $endpoint->getPath(),
                'examples' => $endpoint->getRequestExamples()->count(),
                'href'     => $this->url('nowo_api_studio_endpoint_show', $base + ['id' => $endpoint->getId()]),
                'edit'     => $this->url('nowo_api_studio_endpoint_edit', $base + ['id' => $endpoint->getId()]),
            ];
        }

        return new ListPage(
            header: new PageHeader(
                ApiStudioPages::label('section.endpoints'),
                $service->getBaseUrl(),
                [new Action('create', ApiStudioPages::label('action.new_endpoint'), $this->url('nowo_api_studio_endpoint_new', $base), variant: 'primary')],
                [...$this->serviceCrumbs($workspace, $service), new Crumb(ApiStudioPages::label('section.endpoints'))],
            ),
            columns: [
                new Column('method', ApiStudioPages::label('field.method')),
                new Column('name', ApiStudioPages::label('field.name'), required: true),
                new Column('path', ApiStudioPages::label('field.path'), priority: 2),
                new Column('examples', ApiStudioPages::label('examples.use_cases'), align: 'end', priority: 3),
            ],
            rows: $rows,
            rowActions: static fn (array $row): array => [
                Action::view((string) $row['href'], ApiStudioPages::label('action.open')),
                Action::edit((string) $row['edit'], ApiStudioPages::label('action.edit')),
            ],
            empty: new EmptyState(null, ApiStudioPages::label('empty.endpoints')),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-endpoints'],
            actionsLabel: ApiStudioPages::label('field.actions'),
        );
    }

    /**
     * The request console: header only (the console is the bundle-specific content of the show scaffold).
     */
    public function endpointShow(ApiWorkspace $workspace, ApiService $service, ApiEndpoint $endpoint): ShowPage
    {
        $wid = $workspace->getId();

        return new ShowPage(
            header: new PageHeader(
                $endpoint->getName(),
                $endpoint->getMethod()->value . ' ' . $endpoint->getPath(),
                [
                    new Action('custom', ApiStudioPages::label('section.environments'), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $wid])),
                    Action::edit($this->url('nowo_api_studio_endpoint_edit', ['workspaceId' => $wid, 'serviceId' => $service->getId(), 'id' => $endpoint->getId()]), ApiStudioPages::label('action.edit')),
                ],
                [...$this->serviceCrumbs($workspace, $service), new Crumb($endpoint->getName())],
            ),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-endpoint'],
        );
    }

    public function endpointForm(ApiWorkspace $workspace, ApiService $service, ?ApiEndpoint $endpoint, FormView $form, ?FormView $deleteForm = null): FormPage
    {
        $base  = ['workspaceId' => $workspace->getId(), 'serviceId' => $service->getId()];
        $title = ApiStudioPages::label($endpoint instanceof ApiEndpoint ? 'page.edit_endpoint' : 'page.new_endpoint');
        $back  = $endpoint instanceof ApiEndpoint
            ? $this->url('nowo_api_studio_endpoint_show', $base + ['id' => $endpoint->getId()])
            : $this->url('nowo_api_studio_service_show', ['workspaceId' => $workspace->getId(), 'id' => $service->getId()]);
        $crumbs = $this->serviceCrumbs($workspace, $service);
        if ($endpoint instanceof ApiEndpoint) {
            $crumbs[] = new Crumb($endpoint->getName(), $back);
        }
        $crumbs[] = new Crumb($title);

        return $this->formPage(
            $title,
            $service->getName(),
            $crumbs,
            $back,
            $form,
            $endpoint instanceof ApiEndpoint && $deleteForm instanceof FormView
                ? $this->deleteAction($this->url('nowo_api_studio_endpoint_delete', $base + ['id' => $endpoint->getId()]), $deleteForm, 'api-studio-endpoint-delete')
                : null,
            'api-studio-endpoint-form',
        );
    }

    /**
     * @param iterable<ApiEnvironment> $environments
     */
    public function environmentIndex(ApiWorkspace $workspace, iterable $environments): ListPage
    {
        $wid  = $workspace->getId();
        $rows = [];
        foreach ($environments as $environment) {
            $params = ['workspaceId' => $wid, 'id' => $environment->getId()];
            $rows[] = [
                'id'        => $environment->getId(),
                'name'      => $environment->getName(),
                'default'   => $environment->isDefault(),
                'variables' => $environment->getVariables()->count(),
                'href'      => $this->url('nowo_api_studio_environment_show', $params),
                'edit'      => $this->url('nowo_api_studio_environment_edit', $params),
            ];
        }

        return new ListPage(
            header: new PageHeader(
                ApiStudioPages::label('section.environments'),
                ApiStudioPages::label('variables.key_syntax_hint'),
                [$this->newEnvironmentAction($workspace)],
                [...$this->workspaceCrumbs($workspace), new Crumb(ApiStudioPages::label('section.environments'))],
            ),
            columns: [
                new Column('name', ApiStudioPages::label('field.name'), required: true),
                new Column('variables', ApiStudioPages::label('field.variables'), align: 'end'),
            ],
            rows: $rows,
            rowActions: static fn (array $row): array => [
                Action::view((string) $row['href'], ApiStudioPages::label('action.open')),
                Action::edit((string) $row['edit'], ApiStudioPages::label('action.edit')),
            ],
            empty: new EmptyState(null, ApiStudioPages::label('empty.environments'), $this->newEnvironmentAction($workspace)),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-environments'],
            actionsLabel: ApiStudioPages::label('field.actions'),
        );
    }

    public function environmentShow(ApiWorkspace $workspace, ApiEnvironment $environment): ShowPage
    {
        $wid    = $workspace->getId();
        $params = ['workspaceId' => $wid, 'environmentId' => $environment->getId()];
        $export = [];
        foreach (['json' => 'JSON', 'yaml' => 'YAML', 'env' => '.env'] as $format => $label) {
            $export[] = new DetailItem($label, $format, $this->url('nowo_api_studio_io_export_variables_environment', $params + ['format' => $format]));
        }

        return new ShowPage(
            header: new PageHeader(
                $environment->getName(),
                $environment->getSlug(),
                [
                    new Action('import', ApiStudioPages::label('import.variables.environment_short'), $this->url('nowo_api_studio_io_import_variables_environment', $params)),
                    Action::edit($this->url('nowo_api_studio_environment_edit', ['workspaceId' => $wid, 'id' => $environment->getId()]), ApiStudioPages::label('action.edit')),
                ],
                [...$this->workspaceCrumbs($workspace), new Crumb(ApiStudioPages::label('section.environments'), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $wid])), new Crumb($environment->getName())],
            ),
            sections: [new DetailSection(ApiStudioPages::label('import_export.export_title'), $export, 'api-studio-environment-export')],
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-environment'],
        );
    }

    public function environmentForm(ApiWorkspace $workspace, ?ApiEnvironment $environment, FormView $form, ?FormView $deleteForm = null): FormPage
    {
        $wid   = $workspace->getId();
        $title = ApiStudioPages::label($environment instanceof ApiEnvironment ? 'page.edit_environment' : 'page.new_environment');
        $back  = $environment instanceof ApiEnvironment
            ? $this->url('nowo_api_studio_environment_show', ['workspaceId' => $wid, 'id' => $environment->getId()])
            : $this->url('nowo_api_studio_environment_index', ['workspaceId' => $wid]);
        $crumbs = [...$this->workspaceCrumbs($workspace), new Crumb(ApiStudioPages::label('section.environments'), $this->url('nowo_api_studio_environment_index', ['workspaceId' => $wid]))];
        if ($environment instanceof ApiEnvironment) {
            $crumbs[] = new Crumb($environment->getName(), $back);
        }
        $crumbs[] = new Crumb($title);

        return $this->formPage(
            $title,
            $workspace->getName(),
            $crumbs,
            $back,
            $form,
            $environment instanceof ApiEnvironment && $deleteForm instanceof FormView
                ? $this->deleteAction($this->url('nowo_api_studio_environment_delete', ['workspaceId' => $wid, 'id' => $environment->getId()]), $deleteForm, 'api-studio-environment-delete')
                : null,
            'api-studio-environment-form',
        );
    }

    /**
     * Import / export hub: two detail sections of links.
     */
    public function importExportHub(ApiWorkspace $workspace): ShowPage
    {
        $wid = $workspace->getId();
        $ws  = ['workspaceId' => $wid];

        return new ShowPage(
            header: new PageHeader(
                ApiStudioPages::label('import_export.hub_title'),
                ApiStudioPages::label('import_export.hub_subtitle'),
                breadcrumbs: [...$this->workspaceCrumbs($workspace), new Crumb(ApiStudioPages::label('import_export.hub_title'))],
                back: new BackLink($this->url('nowo_api_studio_workspace_show', ['id' => $wid]), $workspace->getName(), 'api-studio-back'),
            ),
            sections: [
                new DetailSection(ApiStudioPages::label('import_export.import_title'), [
                    new DetailItem(ApiStudioPages::label('import.openapi.title'), ApiStudioPages::label('import.format.openapi'), $this->url('nowo_api_studio_io_import_openapi', $ws)),
                    new DetailItem(ApiStudioPages::label('import.postman.title'), ApiStudioPages::label('import.format.postman'), $this->url('nowo_api_studio_io_import_postman', $ws)),
                    new DetailItem(ApiStudioPages::label('import.variables.workspace_title'), 'JSON · YAML · .env', $this->url('nowo_api_studio_io_import_variables_workspace', $ws)),
                ], 'api-studio-io-import'),
                new DetailSection(ApiStudioPages::label('import_export.export_title'), [
                    new DetailItem(ApiStudioPages::label('export.openapi.workspace'), 'OpenAPI', $this->url('nowo_api_studio_io_export_openapi_workspace', $ws)),
                    new DetailItem(ApiStudioPages::label('export.variables.workspace_json'), 'JSON', $this->url('nowo_api_studio_io_export_variables_workspace', $ws + ['format' => 'json'])),
                    new DetailItem(ApiStudioPages::label('export.variables.workspace_yaml'), 'YAML', $this->url('nowo_api_studio_io_export_variables_workspace', $ws + ['format' => 'yaml'])),
                    new DetailItem(ApiStudioPages::label('export.variables.workspace_env'), '.env', $this->url('nowo_api_studio_io_export_variables_workspace', $ws + ['format' => 'env'])),
                ], 'api-studio-io-export'),
            ],
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-io-hub'],
        );
    }

    /**
     * Import form (OpenAPI, Postman or variables) of a workspace, a service or an environment.
     */
    public function import(ApiWorkspace $workspace, string $titleKey, FormView $form, ?ApiService $service = null, ?ApiEnvironment $environment = null): FormPage
    {
        $wid    = $workspace->getId();
        $crumbs = $this->workspaceCrumbs($workspace);
        if ($service instanceof ApiService) {
            $back     = $this->url('nowo_api_studio_service_show', ['workspaceId' => $wid, 'id' => $service->getId()]);
            $crumbs[] = new Crumb($service->getName(), $back);
        } elseif ($environment instanceof ApiEnvironment) {
            $back     = $this->url('nowo_api_studio_environment_show', ['workspaceId' => $wid, 'id' => $environment->getId()]);
            $crumbs[] = new Crumb($environment->getName(), $back);
        } else {
            $back     = $this->url('nowo_api_studio_io_hub', ['workspaceId' => $wid]);
            $crumbs[] = new Crumb(ApiStudioPages::label('import_export.hub_title'), $back);
        }
        $title    = ApiStudioPages::label($titleKey);
        $crumbs[] = new Crumb($title);

        return new FormPage(
            header: new PageHeader($title, ApiStudioPages::label('import.help'), breadcrumbs: $crumbs, back: new BackLink($back, ApiStudioPages::label('action.back'), 'api-studio-back')),
            form: $form,
            submitLabel: ApiStudioPages::label('import.submit'),
            cancelHref: $back,
            cancelLabel: ApiStudioPages::label('action.cancel'),
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'api-studio-import'],
            formAttributes: ['enctype' => 'multipart/form-data'],
        );
    }

    /**
     * @param list<Crumb> $crumbs
     */
    private function formPage(Label $title, ?string $intro, array $crumbs, string $back, FormView $form, ?Action $delete, string $testid): FormPage
    {
        return new FormPage(
            header: new PageHeader($title, $intro, breadcrumbs: $crumbs, back: new BackLink($back, ApiStudioPages::label('action.back'), 'api-studio-back')),
            form: $form,
            submitLabel: ApiStudioPages::label('action.save'),
            cancelHref: $back,
            cancelLabel: ApiStudioPages::label('action.cancel'),
            deleteAction: $delete,
            bundle: ApiStudioPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => $testid],
        );
    }

    private function deleteAction(string $href, FormView $form, string $testid): Action
    {
        return new Action(
            'delete',
            ApiStudioPages::label('action.delete'),
            $href,
            method: 'POST',
            confirm: new Confirm(
                ApiStudioPages::label('action.delete'),
                ApiStudioPages::label('confirm.delete'),
                ApiStudioPages::label('action.delete'),
                cancelLabel: ApiStudioPages::label('action.cancel'),
            ),
            attributes: ['data-testid' => $testid],
            form: $form,
        );
    }

    private function newWorkspaceAction(): Action
    {
        return new Action('create', ApiStudioPages::label('action.new_workspace'), $this->url('nowo_api_studio_workspace_new'), variant: 'primary', attributes: ['data-testid' => 'api-studio-new-workspace']);
    }

    private function newServiceAction(ApiWorkspace $workspace): Action
    {
        return new Action('create', ApiStudioPages::label('action.new_service'), $this->url('nowo_api_studio_service_new', ['workspaceId' => $workspace->getId()]), variant: 'primary');
    }

    private function newEnvironmentAction(ApiWorkspace $workspace): Action
    {
        return new Action('create', ApiStudioPages::label('action.new_environment'), $this->url('nowo_api_studio_environment_new', ['workspaceId' => $workspace->getId()]), variant: 'primary');
    }

    private function homeCrumb(): Crumb
    {
        return new Crumb(ApiStudioPages::label('brand'), $this->url('nowo_api_studio_dashboard'));
    }

    /**
     * API Studio > Workspaces > workspace (the last one a link unless $linkLast is false).
     *
     * @return list<Crumb>
     */
    private function workspaceCrumbs(ApiWorkspace $workspace, bool $linkLast = true): array
    {
        return [
            $this->homeCrumb(),
            new Crumb(ApiStudioPages::label('page.workspaces'), $this->url('nowo_api_studio_workspace_index')),
            new Crumb($workspace->getName(), $linkLast ? $this->url('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]) : null),
        ];
    }

    /**
     * @return list<Crumb>
     */
    private function serviceCrumbs(ApiWorkspace $workspace, ApiService $service): array
    {
        return [
            ...$this->workspaceCrumbs($workspace),
            new Crumb($service->getName(), $this->url('nowo_api_studio_service_show', ['workspaceId' => $workspace->getId(), 'id' => $service->getId()])),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
