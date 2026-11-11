# Usage

## Table of contents

- [Workspaces](#workspaces)
- [Documenting endpoints](#documenting-endpoints)
- [Demo seed](#demo-seed)
- [Testing requests](#testing-requests)
- [Auth on services](#auth-on-services)
  - [SOAP services](#soap-services)
- [Tenants and permissions](#tenants-and-permissions)
- [Events](#events)
- [Using it from a DDD / hexagonal host](#using-it-from-a-ddd--hexagonal-host)
- [Overriding Twig templates (REQ-TWIG-001)](#overriding-twig-templates-req-twig-001)
  - [Override vs upgrade](#override-vs-upgrade)
  - [Override in the application](#override-in-the-application)
  - [Overridable templates](#overridable-templates)
  - [Forms in the templates (REQ-TWIG-005)](#forms-in-the-templates-req-twig-005)
- [Translations (REQ-I18N)](#translations-req-i18n)
  - [Override in the application](#override-in-the-application-1)

## Workspaces

Create a workspace per product, client, or team. Each workspace owns:

- **Services** — API providers (REST/SOAP/GraphQL)
- **Environments** — variable sets (`local`, `staging`, `prod`)
- **Endpoints** — individual operations

## Documenting endpoints

For each endpoint you can add:

- Translations per locale (`title`, `description`, `notes`)
- Request examples (headers, query, body)
- Response examples (status, headers, body)

## Demo seed

```bash
php bin/console nowo:api-studio:seed-demo
php bin/console nowo:api-studio:seed-demo --fresh
```

Includes reference catalogs for:

- **JSONPlaceholder** — live REST (no auth)
- **LinkedIn API v2** — profile, UGC posts, organization search
- **Google Cloud Translation** — translate, detect, list languages
- **Catastro (España)** — SOAP `Consulta_CPMRC` / `Consulta_RCCOOR` (OVCCoordenadas) and `Consulta_DNPRC` (OVCCallejero) as raw SOAP envelopes, plus HTTP variants

Configure credentials in **Environments** before calling real APIs.

## Testing requests

Open an endpoint detail page, pick an environment, edit the body if needed, and click **Send request**. Results and timing are shown inline; executions are stored in history.

Before persistence, `HistorySanitizer` redacts sensitive headers (`Authorization`, API keys, cookies, …) and common secret patterns in bodies. See [SECURITY.md](SECURITY.md).

Outbound calls use `nowo_api_studio.ui.request_timeout_seconds` (default **30**). See [CONFIGURATION.md](CONFIGURATION.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## Auth on services

Configure `auth_type` on the service:

- `none`, `basic`, `bearer`, `api_key`, `custom`
- Auth config values support `{{variables}}` from the selected environment

### SOAP services

The service **base URL** is the WSDL URL (variables allowed). The endpoint body selects one of two modes:

| Body | Mode | What is sent |
|------|------|--------------|
| A complete SOAP envelope: XML starting with `<` (an XML declaration and BOM are fine) that contains an `Envelope` element (any prefix: `soap:`, `soapenv:`, `SOAP-ENV:`, none) | **Raw envelope** | The body, after `{{variable}}` substitution as for REST bodies, is POSTed **as-is** to the service URL with a trailing `?wsdl` / `?WSDL` / `?singleWsdl` / `?wsdl=…` removed. The WSDL is **not** downloaded and **ext-soap is not needed**. |
| Anything else (normally a JSON object) | **JSON parameters** | The WSDL is downloaded, ext-soap builds the envelope from the JSON object (`{"param": "value"}` → operation arguments) and calls the `<soap:address location>` of the WSDL. Requires ext-soap. |

The endpoint **SOAP action** field (falls back to the endpoint name) can be a bare operation name (`GetThing`) or a full SOAPAction URI (`http://tempuri.org/Svc/GetThing`, `urn:svc#GetThing`):

- Raw-envelope mode sends it verbatim as the action: `SOAPAction: "<action>"` + `Content-Type: text/xml; charset=utf-8` for a SOAP 1.1 envelope, `Content-Type: application/soap+xml; charset=utf-8; action="<action>"` when the envelope uses the SOAP 1.2 namespace (`http://www.w3.org/2003/05/soap-envelope`). Many servers (e.g. .NET ASMX) only accept the exact SOAPAction URI of the WSDL binding, so use the full URI.
- JSON-parameters mode calls the operation named by the last segment of the value (after `/`, `#` or `:`; a bare name is used as-is); the SOAPAction header comes from the WSDL binding.

Both modes use the same guarded transport: the target URL is checked against the SSRF/private-network guard and `execution_url_allowlist`, connected IPs are re-checked (DNS rebinding), the request timeout and `execution_max_redirects` apply, and a 3xx that is not followed is reported as an error. In raw-envelope mode the result is a **failure** when the response contains a SOAP `Fault` (the usual `HTTP 500` fault; the error message is `SOAP Fault (<faultcode>): <faultstring>`, or `Code/Value` + `Reason/Text` for SOAP 1.2) or when the status is not 2xx; the response status, headers and body (the fault envelope) are kept for display. To call a SOAP endpoint whose address differs from the WSDL URL, set the service base URL to that endpoint (raw-envelope mode does not need a WSDL).

Example (raw envelope, the seeded *Catastro — OVCCoordenadas (SOAP)* → `Consulta_RCCOOR`; base URL `https://ovc.catastro.meh.es/ovcservweb/ovcswlocalizacionrc/ovccoordenadas.asmx?WSDL`, SOAP action `http://tempuri.org/OVCServWeb/OVCCoordenadas/Consulta_RCCOOR`):

```xml
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <SRS xmlns="http://www.catastro.meh.es/">{{catastro_srs}}</SRS>
    <CoorX xmlns="http://www.catastro.meh.es/">-3.69214</CoorX>
    <CoorY xmlns="http://www.catastro.meh.es/">40.41378</CoorY>
  </soap:Body>
</soap:Envelope>
```

Example (JSON parameters, an RPC-style WSDL with operation `Ping(value)`): body `{"value": "hello"}`, SOAP action `Ping`. The JSON object is passed to ext-soap as the operation's **single** argument, which fits RPC operations and wrapped document/literal operations; for document/literal *bare* operations with several parts (such as the Catastro ASMX services) write the envelope instead (raw-envelope mode).

SOAP executions send the same HTTP headers as REST: the service auth (`basic` / `bearer` → `Authorization`, `api_key` → the configured header, default `X-Api-Key`), the service default headers, the endpoint headers and the headers edited in the request console (which replace the service/endpoint defaults, as for REST; auth is applied on top). Header precedence:

1. `Content-Type` and `SOAPAction` are owned by the SOAP transport (`text/xml` + `SOAPAction: "<action>"` for SOAP 1.1, `application/soap+xml; action="<action>"` for SOAP 1.2). A header with one of these names (any case) from the service, endpoint or console is **dropped**, never sent twice or allowed to override them; set the action with the endpoint's **SOAP action** field. The endpoint content type is not used for SOAP.
2. Auth headers override a service/endpoint/console header with the same name.
3. Endpoint headers override service default headers; console headers replace both.

The same headers (without the framing ones) are sent when downloading the WSDL, so WSDLs behind auth work, but only to documents on the **same origin** (scheme, host, port) as the WSDL URL: `<xsd:import>` / `<wsdl:import>` documents on another host are fetched without them so credentials never leak to a third party. Redirects follow `execution_max_redirects`; when a redirect leaves the host, the HTTP client drops `Authorization`/`Cookie` but custom headers (e.g. an API key header) are forwarded, so keep `execution_max_redirects: 0` (default) for credentialed SOAP services unless you trust the redirect targets. API key auth is header-only (there is no query-string variant, for REST or SOAP).

Executions are stored in history with the headers that were sent (framing headers excluded in JSON-parameters mode; included in raw-envelope mode, where they are part of the request you wrote), redacted by `HistorySanitizer` exactly like REST: the auth header, the configured API key header and values of secret environment variables are replaced by `[REDACTED]`, and secret values are masked in the stored SOAP envelope (in both modes).

## Tenants and permissions

Since 1.11 a host can give every tenant (organization, team…) its own studio and decide per workspace and per action
who may do what. Everything is opt-in: without configuration the bundle behaves as in 1.10.

```yaml
nowo_api_studio:
    ui:
        path: '/organizations/{organization}/api-studio'
        path_requirements: {organization: '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'}
        locale_scope: none
    tenancy:
        enabled: true
        resolver: App\ApiStudio\OrganizationTenantResolver
    security:
        permission_checker: App\ApiStudio\ApiStudioPermissionChecker
    history:
        scope: user
```

- **Tenant resolver** (`ApiStudioTenantResolverInterface::resolve(Request): ?ApiStudioTenant`): called once per Api
  Studio request after routing; identify only (e.g. read `{organization}`), never authorize. `null` → 404. Commands
  run as a tenant with `ApiStudioTenantContext::runAs()`; `nowo:api-studio:seed-demo --tenant-type=… --tenant-id=…`.
- **Permission checker** (`ApiStudioPermissionCheckerInterface`): `isGranted(?object $user, ApiStudioAction $action,
  ApiStudioSubject $subject)` and `visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?list<int>` (null = all
  of the tenant). Actions: `studio.open`, `workspace.create`, `workspace.view`, `workspace.run`, `workspace.edit`,
  `workspace.edit_scripts`, `workspace.manage`, `workspace.import`, `workspace.export`, `workspace.export_variables`,
  `history.view_all`. Deny unknown actions. `ApiStudioRouteActions::MAP` lists the action of every route (a route
  missing from it answers 404).
- **Outcomes**: a workspace of another tenant, not in `visibleWorkspaceIds()` or without `workspace.view` answers 404;
  viewable without the action 403 (`{"error":"forbidden"}` on `/execute`); `studio.open` denied 404,
  `workspace.create` denied 403. Pages and the Twig function `nowo_api_studio_can('workspace.edit', workspace)` hide
  what is not granted; script fields are left out of the forms without `workspace.edit_scripts`.
- **Events**: `ApiWorkspaceCreatedEvent` (workspace, tenant, user) is dispatched inside the creating transaction — a
  host listener writes atomically with the workspace, and an exception rolls it back; `ApiWorkspaceDeletedEvent` after
  a delete.
- **Migration** (tenancy): `ALTER TABLE api_studio_workspace ADD tenant_type VARCHAR(32) NOT NULL DEFAULT '', ADD
  tenant_id VARCHAR(64) NOT NULL DEFAULT ''`, replace the unique index `api_studio_uniq_workspace_slug` by
  `api_studio_uniq_workspace_tenant_slug (tenant_type, tenant_id, slug)` and add `api_studio_idx_workspace_tenant
  (tenant_type, tenant_id)`; then backfill the tenant of existing workspaces from the host's own data. Existing rows
  start in the instance tenant `('', '')`. `doctrine:migrations:diff` generates the DDL.
- **Defence in depth**: with `tenancy.sql_filter` (default) the Doctrine filter `nowo_api_studio_tenant` is enabled
  during Api Studio requests; it does not cover entities already loaded, DBAL queries or DQL UPDATE / DELETE, which is
  why the controllers check every workspace explicitly.
- **Maintenance commands** (tenancy on): `nowo:api-studio:tenant:orphans [--json]` lists workspaces without a tenant;
  `nowo:api-studio:tenant:move <id> --to-type=… --to-id=… [--dry-run]` moves one (refused when the slug is taken at the
  destination; dispatches `ApiWorkspaceMovedEvent`); `nowo:api-studio:tenant:unsuffix-slugs --tenant-type=…
  --tenant-id=… [--dry-run]` strips `-2`, `-3`… suffixes when the base slug is free in the tenant.
- **Slugs**: leave the slug empty to derive it from the name; new or edited slugs must match
  `^[a-z0-9]+(?:[-_][a-z0-9]+)*$` (≤ 128).

## Events

Every event is dispatched through the Symfony event dispatcher (event name = class name).

| Event | When | Carries |
|-------|------|---------|
| `ApiWorkspaceCreatedEvent` | Workspace created from the studio, after its flush and **inside** the transaction (a listener exception rolls the workspace back) | `workspace` (entity), `tenant`, `user`; since 1.12 also `workspaceId`, `slug`, `actorId` |
| `ApiWorkspaceCreatedCommittedEvent` (1.12) | The same workspace, **after the commit** | `workspaceId`, `slug`, `tenant`, `actorId` |
| `ApiWorkspaceDeletedEvent` | After a workspace was deleted | `workspaceId`, `tenant` |
| `ApiWorkspaceMovedEvent` | `nowo:api-studio:tenant:move` moved a workspace | `workspaceId`, `from`, `to` |
| `ApiStudioResourceChangedEvent` (1.12) | After a flushed create / update / delete / import of a workspace, service, endpoint (incl. its documentation), environment, the variables of an environment, or a request / response example | `resourceType`, `resourceId`, `workspaceId`, `action`, `actorId` |
| `ApiRequestExecutedEvent` (1.12) | After the console executed a request and stored its history row | `endpointId`, `serviceId`, `workspaceId`, `environmentId`, `historyId`, `method`, `host`, `statusCode`, `durationMs`, `success`, `actorId` — never the path, query, headers, bodies or variable values |

`actorId` is the same opaque id history rows record (`history.actor_resolver`, else `u:` + the user identifier, hashed
beyond 62 characters); null for anonymous users. Use the in-transaction `ApiWorkspaceCreatedEvent` for host rows that
must be atomic with the workspace, and the committed / changed / executed events for outboxes, audit logs, search
indexes and anything that talks to another system.

## Using it from a DDD / hexagonal host

Api Studio is a self-contained bounded context: its nine entities are bundle-owned and reference the host only through
the tenant value object (`tenant_type` / `tenant_id` strings) and the opaque actor id. Both kinds of host are supported.

**Standard Symfony host (default, zero configuration).** The entities are mapped on the default entity manager, the
controllers flush it for the studio's own writes (admin UI, explicitly triggered by the user), the security user is
whatever your firewall returns, and listeners may use the entities on the events.

**DDD / hexagonal host (opt-in).**

```yaml
doctrine:
    dbal:
        connections:
            default: { url: '%env(DATABASE_URL)%' }
            api_studio: { url: '%env(DATABASE_URL)%' }   # same database is fine; a separate connection keeps transactions apart
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                mappings: { App: { type: xml, dir: '%kernel.project_dir%/config/doctrine', prefix: 'App\Domain' } }
            api_studio:
                connection: api_studio

nowo_api_studio:
    entity_manager: api_studio             # 1.12: entities, tenant filter, flushes and resets on this manager only
    history:
        actor_resolver: App\Infrastructure\ApiStudio\ActorResolver    # opaque public id, no e-mail in the tables
    security:
        permission_checker: App\Infrastructure\ApiStudio\PermissionChecker   # decide by $subject->workspaceId
    tenancy:
        enabled: true
        provider: App\Infrastructure\ApiStudio\TenantProvider          # 1.12: request-free ApiStudioTenantProviderInterface
```

- The bundle never flushes, clears or resets your manager: Api Studio writes go to `api_studio` only, and the recovery
  of a closed manager on the next request (FrankenPHP worker mode, no services resetter needed) resets `api_studio`
  only. The entities are not mapped on your managers even with `auto_mapping: true`, and the bundle prepends nothing
  into your ORM configuration (no phantom `default` manager; your `default_entity_manager` stays yours).
- Permission checkers receive `ApiStudioSubject` with `workspaceId` (1.12) next to the entity: store grants by id.
- The tenant comes from your application context through `ApiStudioTenantProviderInterface::current()` (no HTTP
  request in your port). Read it per call; do not cache it in a property (worker mode).
- React to the id events (`ApiWorkspaceCreatedCommittedEvent`, `ApiStudioResourceChangedEvent`,
  `ApiRequestExecutedEvent`) from an infrastructure listener and translate them into your own domain events or outbox
  rows. They fire after the bundle's commit; when your own code wraps the request in an outer transaction, they fire
  after the bundle's (nested) commit, before yours.
- A "host controls the transaction" mode does not apply here: every write comes from the studio's own UI controllers,
  never from a host command handler. The dedicated manager is the DDD path.
- `sync-schema` and the `tenant:*` commands use the configured manager.

## Overriding Twig templates (REQ-TWIG-001)

Bundle templates use the **`@NowoApiStudioBundle`** namespace (`src/Resources/views/`). The pages follow the UiKit page
contract (REQ-UI-003): each one extends a UiKit scaffold and fills data blocks; the page list, view models and blocks
are in [TEMPLATES.md](TEMPLATES.md).

### Override vs upgrade

Twig overrides and package upgrades pull in opposite directions; pick the shallowest depth that does the job:

| Depth | How | Customisation | On bundle version bump |
| --- | --- | --- | --- |
| **Preferred — no Twig fork** | `nowo_ui_kit.layouts.admin` (your layout or a one-file bridge), `nowo_ui_kit.theme` (classes, attributes, partials, `--nowo-ui-*` tokens, `theme.bundles.NowoApiStudioBundle`), `nowo_api_studio.ui.layout_template` for an Api Studio-only layout | Host chrome + look and feel of every Api Studio page | Vendor templates and fixes apply automatically; no app Twig to re-touch |
| **Surgical override** | Copy one partial (e.g. `_sidebar_tree.html.twig`) or a thin page that `extends` the bundle page and overrides one block with `{{ parent() }}` | One concern | Only that file is frozen; the rest of the UI still upgrades |
| **Full-file override** | Copy a page into `templates/bundles/NowoApiStudioBundle/<subpath>` | Total control | That `<subpath>` is **frozen** |

**Freeze rule:** an application file at the override path always wins and will **not** pick up vendor changes for that
path until you remove it or merge it by hand. Full-file overrides are an escape hatch for deep redesigns, not the
default integration path.

**Recovering upstream after a freeze:** delete (or rename aside) the app override, clear the cache
(`php bin/console cache:clear`), diff your copy against the new vendor file under
`vendor/nowo-tech/api-studio-bundle/src/Resources/views/<subpath>`, and re-apply only surgical customisations if still
needed.

Renaming or removing a published `<subpath>` or data block is a breaking change, announced in
[UPGRADING.md](UPGRADING.md) (see "Template contract").

### Override in the application

1. Find the `<subpath>` in the table below (path relative to `src/Resources/views/`).
2. Create `templates/bundles/NowoApiStudioBundle/<subpath>` in the application (same relative path and file name).
3. Prefer the smallest file. Example (surgical): a custom API tree, everything else keeps upgrading:

   ```twig
   {# templates/bundles/NowoApiStudioBundle/_sidebar_tree.html.twig #}
   {# copied from vendor/nowo-tech/api-studio-bundle/src/Resources/views/_sidebar_tree.html.twig, then edited #}
   ```

   Variables of each template are documented in its header comment and in [TEMPLATES.md](TEMPLATES.md).
4. Clear the cache in dev if needed: `php bin/console cache:clear`.
5. Treat the file as a fork of that `<subpath>` (freeze rule above).

Symfony searches `templates/bundles/NowoApiStudioBundle/` before the bundle views (`TwigPathsPass` appends the bundle
path to the namespace), so overrides always win. The namespace is registered only when the bundle is enabled
(`nowo_api_studio.enabled`, see [CONFIGURATION.md](CONFIGURATION.md)).

### Overridable templates

| Subpath | Purpose |
| --- | --- |
| `dashboard/index.html.twig` | Dashboard (UiKit `page/dashboard`: KPI tiles + workbench with the projects card) |
| `workspace/index.html.twig` | Workspace list (`page/list`) |
| `workspace/show.html.twig` | Workspace (`page/show`: details, service collections, import / export) |
| `workspace/form.html.twig` | Create / edit workspace (`page/form`, delete zone) |
| `service/index.html.twig` | Service list (`page/list`) |
| `service/show.html.twig` | Service (`page/show`: details, endpoints with saved requests, variables, imports) |
| `service/form.html.twig` | Create / edit service (`page/form`, type-to-confirm delete) |
| `endpoint/index.html.twig` | Endpoint list (`page/list`) |
| `endpoint/show.html.twig` | Request console (`page/show`) |
| `endpoint/form.html.twig` | Create / edit endpoint (`page/form`, documentation per locale) |
| `environment/index.html.twig` | Environment list (`page/list`) |
| `environment/show.html.twig` | Environment (`page/show`: variables, exports) |
| `environment/form.html.twig` | Create / edit environment (`page/form`, delete zone) |
| `import_export/hub.html.twig` | Import / export hub (`page/show`) |
| `import_export/import.html.twig` | Import form (`page/form`) |
| `import_export/_workspace_panel.html.twig` | Workspace import / export card |
| `_workbench.html.twig` | Workbench: API tree beside the page content |
| `_sidebar_tree.html.twig` | API tree and filter |
| `_sidebar_endpoints.html.twig` | Endpoints and saved requests of one service in the tree |
| `_endpoint_expandable_list.html.twig` | Expandable endpoint rows with saved requests |
| `_service_collection_list.html.twig` | Collapsible services (endpoint collections) |
| `_service_kebab_menu.html.twig` | Service actions menu (UiKit kebab) |
| `_service_action_modals.html.twig` | Service export / import / delete dialogs (UiKit `<dialog>`) |
| `_variable_catalog.html.twig` | Workspace variable catalogue |
| `_styles.html.twig`, `_scripts.html.twig` | Api Studio asset tags |
| `_legacy_layout.html.twig` | Deprecated bridge for a pre-1.10 layout (removed in 2.0) |

**Layout chain (REQ-UI-003):** pages extend a UiKit scaffold, which extends `page.layout` = `ui.layout_template` when set,
else `nowo_ui_kit.layouts.admin`. Api Studio assets stack in `nowo_ui_styles` / `nowo_ui_scripts` after
`{{ parent() }}`; overrides of those blocks must keep `{{ parent() }}`.

Protect the UI with Symfony `access_control` on the configured path (default `/api-studio`). See [CONFIGURATION.md](CONFIGURATION.md) (`security.access_roles`, `security.allow_unauthenticated`).

### Forms in the templates (REQ-TWIG-005)

Every control in the API Studio views is a Symfony Form view built from a **FormKit type** (profile `api_studio`, `#[FormKitConfig]` + `FormOptionsTrait`, REQ-TWIG-005 rule 11 — editors, imports, CSRF-only deletes, client-side controls, tester rows); the templates never contain raw `<form>` / `<input>` / `<select>` / `<textarea>` markup (`make check-no-raw-html-form`, part of `release-check`):

- **Editors** (workspace, service, endpoint, environment, import): FormKit-configured types rendered with `form_start`, the child loop (`{% for child in form %}{% if not child.rendered %}{{ form_row(child) }}{% endif %}{% endfor %}`) and `form_end`.
- **Delete buttons:** `CsrfOnlyType` (flat `_token`, token id `delete<id>`) built by the controllers and passed to the page as the `FormView` of the UiKit delete `Action` (form page delete zone, behind the UiKit confirm dialog; the service form also carries `confirmation`). `api_studio_csrf_form(action, csrfTokenId)` stays available for host templates. The controllers validate the POST with the same type.
- **Layout partials** build their own forms: `api_studio_tree_filter_form()` (sidebar filter) and `api_studio_service_modal_forms()` (export / import format radios, `import_file_form` upload, service delete confirmation).
- **Endpoint tester** (`endpoint/show.html.twig`): the controller always passes `testerForm` (`EndpointTesterFormFactory`, unmapped client-side controls with the historical ids / classes). Because these controls are spread over several blocks and never submitted, they are rendered with `form_widget` / `form_label` only.

Overrides and demos must keep using these Form views; do not reintroduce raw form controls.

## Translations (REQ-I18N)

Domain: **`NowoApiStudioBundle`**

The bundle ships catalogues for **`de`**, **`en`**, **`es`**, **`fr`**, **`it`**, **`nl`**, and **`pt`** under `src/Resources/translations/`.

### Override in the application

```yaml
# translations/NowoApiStudioBundle.es.yaml
workspace:
    title: Mis espacios de trabajo
```

Symfony uses application translations first; missing keys fall back to the bundle.

UI/documentation locales are configured via `nowo_api_studio.ui.locales` (see [CONFIGURATION.md](CONFIGURATION.md)).
