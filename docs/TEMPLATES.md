# Templates (UiKit page contract)

Since **1.10** every Api Studio page extends a [UiKitBundle](https://github.com/nowo-tech/UiKitBundle) page scaffold
(REQ-UI-003). The host themes the scaffolds once (`nowo_ui_kit.layouts`, `nowo_ui_kit.theme`) and every Api Studio
page follows; the bundle ships no HTML layout, no CDN asset, no framework class of its own and no local copy of the
UiKit partials (pagination, flashes, page header, dialog, confirm, kebab, locale switcher).

Templates live in `src/Resources/views/` (Twig namespace `@NowoApiStudioBundle`). Overriding one file and the
upgrade trade-off are described in [USAGE.md](USAGE.md#overriding-twig-templates-req-twig-001).

## Table of contents

- [Pages](#pages)
- [View models](#view-models)
- [Bundle partials](#bundle-partials)
- [Layout](#layout)
- [Assets](#assets)
- [Per-locale fields](#per-locale-fields)
- [Unique pages](#unique-pages)
- [Contract test](#contract-test)

## Pages

Every view model passes `bundle: 'NowoApiStudioBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoApiStudioBundle`)
and `layout: ApiStudioPages::layout()`. Labels are `NowoApiStudioBundle` translation keys
(`ApiStudioPages::label()`); the scaffolds translate them.

| Template | Scaffold | View model (`page`, built by `ApiStudioPageFactory`) | Data blocks filled | Other variables |
| --- | --- | --- | --- | --- |
| `dashboard/index.html.twig` | `page/dashboard` | `DashboardPage` (`dashboard()`: header `page.dashboard` / `dashboard.subtitle`, action New workspace; KPI tiles workspaces (link), services, endpoints) | `nowo_ui_dashboard_cards` (workbench: API tree + projects card) | `workspaces` (first `ui.list_page_size`), `workspaceCount`, `serviceCount`, `endpointCount` |
| `workspace/index.html.twig` | `page/list` | `ListPage` (`workspaceIndex()`: columns name, slug; rows `{id, name, slug, href, edit}`; row actions Open / Edit; `Pagination` of `ui.list_page_size`; `EmptyState` with New workspace) | `nowo_ui_list_row` | — |
| `workspace/show.html.twig` | `page/show` | `ShowPage` (`workspaceShow()`: header name / description, actions New service, New environment, Environments, Import / Export, Edit; breadcrumbs; section details) | `nowo_ui_show_sections` (workbench: tree, the default sections, service collections, import / export card), `nowo_ui_modals` (service dialogs) | `workspace` |
| `workspace/form.html.twig` | `page/form` | `FormPage` (`workspaceForm()`: `ApiWorkspaceFormType`, Save / Cancel, back link; on edit the delete zone: `Action` delete with the CSRF-only `FormView` behind a UiKit `Confirm`) | — (scaffold child loop) | — |
| `service/index.html.twig` | `page/list` | `ListPage` (`serviceIndex()`: columns name, protocol, base URL, endpoints; row actions Open service / View all endpoints / Edit) | `nowo_ui_list_row` | — |
| `service/show.html.twig` | `page/show` | `ShowPage` (`serviceShow()`: actions New endpoint, Environments, Edit; section details: protocol, base URL, auth, endpoints) | `nowo_ui_show_sections` (workbench: tree, details, endpoints with saved requests and the service menu, workspace variables, import links), `nowo_ui_modals` (service dialogs) | `workspace`, `service`, `variableCatalog`, `environments` |
| `service/form.html.twig` | `page/form` | `FormPage` (`serviceForm()`: `ApiServiceFormType`; on edit the delete zone behind a type-to-confirm `Confirm` (`expectedValue` = service name; the CSRF form carries `confirmation`)) | — | — |
| `endpoint/index.html.twig` | `page/list` | `ListPage` (`endpointIndex()`: columns method, name, path, saved requests; row actions Open / Edit) | `nowo_ui_list_row` (method badge) | — |
| `endpoint/show.html.twig` | `page/show` | `ShowPage` (`endpointShow()`: header name / method + path, actions Environments, Edit; breadcrumbs) | `nowo_ui_show_sections` (workbench: tree + request console), `nowo_ui_modals` (save example and delete example dialogs) | `workspace`, `service`, `endpoint`, `testerForm`, `documentationByLocale`, `enabledLocales`, `defaultLocale`, `currentLocale`, `environments`, `environmentMaps`, `variableCatalog`, `mergedHeaders`, `history`, execute / examples / documentation URLs and CSRF tokens, `loadRequestExampleId` |
| `endpoint/form.html.twig` | `page/form` | `FormPage` (`endpointForm()`: `ApiEndpointFormType`; delete zone on edit) | `nowo_ui_form_body` (child loop without `translations`, then the FormKit locale tabs) | `enabledLocales`, `currentLocale` |
| `environment/index.html.twig` | `page/list` | `ListPage` (`environmentIndex()`: columns name (+ default badge), variables; row actions Open / Edit) | `nowo_ui_list_row` | — |
| `environment/show.html.twig` | `page/show` | `ShowPage` (`environmentShow()`: header name / slug, actions Import variables, Edit; section export JSON / YAML / .env) | `nowo_ui_show_sections` (variables table with masked secrets, then `{{ parent() }}`) | `environment` |
| `environment/form.html.twig` | `page/form` | `FormPage` (`environmentForm()`: `ApiEnvironmentFormType`; delete zone on edit) | — | — |
| `import_export/hub.html.twig` | `page/show` | `ShowPage` (`importExportHub()`: sections Import and Export as detail items with links; back link to the workspace) | — (scaffold sections) | — |
| `import_export/import.html.twig` | `page/form` | `FormPage` (`import()`: `ImportFileFormType` as multipart, submit `import.submit`, cancel = service, environment or hub) | `nowo_ui_form_body` (the form type's own submit button is marked rendered: the scaffold renders the submit) | — |

Every page also fills `nowo_ui_styles` (`{{ parent() }}` + `_styles.html.twig`) and, when it has client behaviour,
`nowo_ui_scripts` (`{{ parent() }}` + `_scripts.html.twig`). Flash messages are printed by the layout's
`nowo_ui_flashes`; the controllers translate them when they add them. Invalid form submissions answer HTTP 422.

## View models

`Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) and
`Nowo\ApiStudioBundle\Ui\ApiStudioPages` resolves the layout. List rows are plain arrays (no Doctrine entity in a view
model); the show pages also receive the entities their data blocks read (`workspace`, `service`, `endpoint`,
`environment`).

## Bundle partials

| Partial | Purpose | Override notes |
| --- | --- | --- |
| `_workbench.html.twig` | Two-column workbench (API tree beside the content); embed it and fill `as_workbench_main`. Variables `bundle`, `nav_active` (`{workspace, service, endpoint}`) | The one layout partial of the bundle |
| `_sidebar_tree.html.twig` | API tree from `api_studio_nav_tree()` (one query), client-side filter (`api_studio_tree_filter_form()`), UiKit locale switcher | Meant for overriding (variables `nav_active`, `bundle`) |
| `_sidebar_endpoints.html.twig` | Endpoints and saved requests of one service in the tree (`svc`, `active_ep`) | — |
| `_service_collection_list.html.twig` | Collapsible services with their endpoints (`services`, `workspace`, `bundle`) | — |
| `_endpoint_expandable_list.html.twig` | Endpoint rows with their saved requests (`endpoints`, `service`, `workspace`) | — |
| `_service_kebab_menu.html.twig` | Service actions through the UiKit `kebab` partial (Export / Import / Delete open the service dialogs) | — |
| `_service_action_modals.html.twig` | Export, import and type-to-confirm delete dialogs: UiKit `dialog` partial (native `<dialog>`), filled by `api-studio-shell.js` | Keep the element ids |
| `_variable_catalog.html.twig` | Workspace variables (placeholder, description, secret) on the UiKit table slots | — |
| `import_export/_workspace_panel.html.twig` | Workspace import / export links card | — |
| `_styles.html.twig`, `_scripts.html.twig` | Asset tags of the `nowo_api_studio` package (scripts deferred, with the request `csp_nonce`) | — |
| `_legacy_layout.html.twig` | Deprecated bridge for a pre-1.10 layout (see [Layout](#layout)) | Removed in 2.0 |

Bundle-specific markup uses UiKit theme slots (`card`, `card_header`, `card_title`, `card_intro`, `card_body`,
`table*`, `btn_*`, `badge_*`, `code`, `muted`, `toolbar`, `form`, `form_actions`, `input`) plus the semantic `as-*`
hooks of the workbench and the request console, styled by `api-studio-theme.css` with the `--nowo-ui-*` tokens (light
and dark follow the host theme).

## Layout

The scaffolds extend `page.layout ?? nowo_ui_layout.admin`:

- `nowo_api_studio.ui.layout_template: null` (default, and the 1.x default `@NowoApiStudioBundle/layout.html.twig`):
  `nowo_ui_kit.layouts.admin` — set it once for every UiKit bundle.
- A layout that renders the UiKit canonical blocks (`nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`,
  `nowo_ui_modals`, `nowo_ui_styles`, `nowo_ui_scripts`, `nowo_ui_document_title`): used as is.
- A pre-1.10 layout (renders `{% block body %}` / `stylesheets` / `javascripts` only): wrapped in the **deprecated**
  bridge `@NowoApiStudioBundle/_legacy_layout.html.twig` with a deprecation notice (removed in 2.0).

## Assets

`api-studio-theme.css` (every page), `api-studio-shell.js` (tree, collections, service dialogs, console sub-tabs) and on
the request console `api-body-tools.js`, `api-endpoint-doc.js`, `api-script-runtime.js`, `api-tester.js` — all from
the `nowo_api_studio` asset package, deferred, no inline script or handler. The UiKit runtimes (dialog, confirm, tabs,
popover…) come from the layout's `scripts` partial.

## Per-locale fields

| Template | Page | Rendering |
| --- | --- | --- |
| `endpoint/form.html.twig` | endpoint create / edit | `translations` (CollectionType of `ApiEndpointTranslationFormType`, FormKit option `locale_tabs`: mode `entries`, `locales` = `ui.locales`, `default_locale` = `ui.default_locale`) rendered with `@NowoFormKitBundle/form/_locale_tabs.html.twig`, tabs id `endpoint-form-locale`, the session UI locale opened |
| `endpoint/show.html.twig` | request console (read-only documentation) | UiKit `nowo_ui_partial('locale_tabs')` with one panel per locale (`[data-doc-title]`, `[data-doc-description]`), tabs id `endpoint-doc-locale`; `api-endpoint-doc.js` edits the open panel's locale |

DOM ids follow UiKit: root `#<tabs id>`, buttons `#tab-<tabs id>-<locale>`, panels `#tab-<tabs id>-<locale>-panel`.

## Unique pages

None: every Api Studio page extends a UiKit scaffold. The request console is the bundle-specific content of the
show scaffold (`nowo_ui_show_sections`).

## Contract test

`tests/Integration/Contract/PageContractTest.php` extends `Nowo\UiKitBundle\Test\PageContractTestCase` and renders every
page (28 fixtures from `tests/Support/ApiStudioPageFixtures.php`, built with `ApiStudioPageFactory` and the real form
types of the functional test kernel) against the UiKit stub host layout and theme, for `none`, `bootstrap5` and
`tailwind`, with the strict missing-translation rule. It also checks the workbench tree, the service dialogs, the
confirm contract of the deletes and the CSP nonce of the console scripts.
