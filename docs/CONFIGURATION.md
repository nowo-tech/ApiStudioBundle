# Configuration

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    enabled: true
    connection: default
    entity_manager: null         # 1.12: e.g. api_studio = dedicated manager (DDD hosts)
    table_prefix: api_studio_
    environments: [dev, test, prod]
    ui:
        path: '/api-studio'      # may hold {placeholders} since 1.11, e.g. '/organizations/{organization}/api-studio'
        path_requirements: {}    # e.g. {organization: '[0-9a-f-]{36}'}; default [^/]+
        locale_scope: routes     # routes (1.11 default) | global (1.10 behaviour) | none
        layout_template: null    # null = nowo_ui_kit.layouts.admin (UiKit page contract)
        header_actions: header   # 1.23: header (buttons in the header row) | page_actions (UiKit page-actions menu)
        # css_framework: deprecated since 1.10 — set nowo_ui_kit.css_framework instead
        default_locale: en
        locales: [en, es, fr, it]
        request_timeout_seconds: 30
        list_page_size: 50
        # Legacy fallback when security.access_roles is empty
        required_roles: [ROLE_ADMIN]
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
        # access_checker: App\Security\MyApiStudioAccessChecker
        # permission_checker: App\ApiStudio\MyPermissionChecker   # 1.11: per-workspace, per-action decisions
        # feature_gate: App\ApiStudio\PlanFeatureGate             # 1.16: plan / feature switch on every route
        access_denied_response: 403                                # 1.16: 403 | 404 (404 = refusals look like not-found)
        host_routes: {}                                            # 1.16: {app_api_studio_share: studio.open}
    tenancy:                     # 1.11
        enabled: false           # literal boolean; maps tenant_type / tenant_id on the workspace table
        # resolver: App\ApiStudio\MyTenantResolver               # required when enabled (or provider)
        # provider: App\ApiStudio\MyTenantProvider               # 1.12: request-free alternative to resolver
        sql_filter: true         # defence-in-depth Doctrine filter during Api Studio requests (1.17: also narrows by scope)
    scope:                       # 1.17: workspaces scoped to a host sub-scope (e.g. a project); off by default
        enabled: false           # literal boolean; maps host_scope on the workspace table (migration)
        # resolver: App\ApiStudio\ProjectScopeResolver            # required when enabled (ScopeResolverInterface)
        # access_checker: App\ApiStudio\ProjectScopeChecker       # required when enabled (ScopeAccessCheckerInterface)
        # label_provider: App\ApiStudio\ProjectScopeLabels        # optional badge on tenant-level lists
        # path: '/organizations/{organization}/projects/{project}/api-studio'   # scoped mount (twin routes)
        path_requirements: {}    # e.g. {project: '[0-9a-f-]{36}'}; default [^/]+
        layout_template: null    # layout of scoped pages (null = ui.layout_template), e.g. the project page
        tenant_list: all         # all | unscoped: what tenant-level pages list
    concurrency:                 # 1.17 (REQ-RUNTIME-003)
        conflict_status: 409     # 409 | 503 for lock conflicts on Api Studio routes
        retry_after: 1           # Retry-After seconds of a CONFLICT_001 answer
    history:
        scope: endpoint          # endpoint | user (maps executed_by)
        # actor_resolver: App\ApiStudio\MyActorResolver
        retention_days: 90       # 1.24: purge rows older than this (0 = keep forever); nothing runs until scheduled
        purge_batch_size: 500    # 1.24: rows per batch (one short transaction each), 10–5000
    personal_data:
        enabled: true            # 1.24: UserKit eraser + export section "api_studio" when UserKit is installed
    search_kit:                  # Unreleased: SearchKit groups (needs nowo-tech/search-kit-bundle ^1.9)
        enabled: false           # literal boolean; opt-in
        groups:
            workspaces: true     # by name
            services: true       # by name
            endpoints: true      # by name, path and HTTP method
    folders:
        enabled: false           # 1.14: nested folders (migration)
        max_depth: 8
    scripts:
        mode: inline             # inline (deprecated in 1.15) | sandbox (1.15) | disabled
        limits:
            time_ms: 2000        # 1.15: wall time per phase (sandbox frame and server runner)
            memory_mb: 64        # 1.15: server runner sidecar memory ({memory_mb} of the command)
            output_kb: 256       # 1.15: server runner stdout + stderr per run
            send_requests: 10    # 1.15: pm.sendRequest calls per phase (sandbox and server runner)
        server_runner:           # 1.15: unattended runs (needs proc_open)
            enabled: false
            command: [deno, run, --quiet, --no-prompt, --no-config, --no-lock, --no-npm, --no-remote, '--v8-flags=--max-old-space-size={memory_mb}', '{runner}']
        confirm_stored: true     # 1.13.2: ask before running stored scripts not yet approved
    execution_url_allowlist_required: false
    execution_url_allowlist: []
    execution_max_redirects: 0
    execution_max_response_bytes: 10485760   # 10 MiB
    # Examples:
    # execution_url_allowlist_required: true
    # execution_url_allowlist:
    #   - api.example.com                 # exact host
    #   - .example.org                    # example.org and any subdomain
    #   - 'https://api.example.net:8443/v1' # scheme + host + port + path prefix
    #   - '#^https://staging\.example\.com/#'
    secrets:
        encrypt: true
        # encryption_key: '%env(NOWO_API_STUDIO_SECRETS_KEY)%'
        # source: App\Infrastructure\ApiStudio\VaultSecretSource   # 1.13: host secret references
```

| Option | Description |
|--------|-------------|
| `enabled` | Enable or disable the bundle (default `true`). When `false`, every Api Studio route (`nowo_api_studio_*`) answers 404. |
| `connection` | Doctrine connection name used by sync-schema (default `default`). |
| `entity_manager` | **1.12.** Name of the Doctrine entity manager the Api Studio entities are mapped on (default `null` = the manager that maps them, the default one in a standard host: nothing changes). When set, the entities and the tenant SQL filter are mapped on that manager **only** (also when the default manager has `auto_mapping: true` or a default metadata driver — DoctrineBundle 3.4 `auto_discover_entities`; fixed in Unreleased), nothing is prepended into the host's ORM configuration, and every read, write, flush and recovery reset of the bundle (controllers, importers, commands, `sync-schema`) uses it — never the host's default manager. The manager must exist under `doctrine.orm.entity_managers` or the build fails. See [USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-host). |
| `table_prefix` | Prefix for all bundle tables and unique indexes (default `api_studio_`). Lowercase letters, numbers, and underscores only. |
| `environments` | Environments created automatically when a workspace is created from the UI (default `dev`, `test`, `prod`); the first one is marked as default. Slugs are derived from the names (lowercase, non-alphanumerics → `_`). Set `[]` to create workspaces without environments. Imports and the demo seeder are not affected. |
| `ui.path` | Dashboard base path / path prefix (default `/api-studio`). Lock with Symfony `access_control`. |
| `ui.header_actions` | **1.23.** Where the create actions of the pages go. `header` (default): buttons in the page header row. `page_actions`: "New workspace", "New service", "New environment", "New endpoint", "New folder" and the other header actions are UiKit `View\Action` entries of the page-actions menu (UiKit split header, create actions `secondary`); the empty states keep their primary action. Needs UiKit 2.21+. Same option as VaultBundle `web_ui.header_actions`. See [USAGE.md](USAGE.md#header-actions-123). |
| `ui.layout_template` | Layout the Api Studio pages (UiKit page scaffolds) extend. `null` (default; the 1.x default `@NowoApiStudioBundle/layout.html.twig` reads as null) = `nowo_ui_kit.layouts.admin`, configured once for every UiKit bundle. A layout that renders the UiKit canonical blocks is used as is; a pre-1.10 layout that only renders `{% block body %}` is wrapped in a deprecated bridge (removed in 2.0). Twig global `nowo_api_studio_layout_template`. See [TEMPLATES.md](TEMPLATES.md#layout). |
| `ui.css_framework` | **Deprecated since 1.10** (default `null`): the CSS stack is `nowo_ui_kit.css_framework`. When set (and the host did not set `nowo_ui_kit.css_framework` / `nowo_form_kit.css_framework`), it still seeds UiKit (`css_framework`, `icon_set: bootstrap-icons`) and FormKit, and triggers a deprecation. The bundle loads no CSS framework (no CDN). |
| `ui.path_requirements` | **1.11.** Requirements of the `{placeholders}` of `ui.path` (a placeholder without one matches `[^/]+`). Placeholder names must be lowercase identifiers and not a route parameter of the bundle (`id`, `workspaceId`, `serviceId`, `endpointId`, `environmentId`, `exampleId`, `format`, `_locale`…); an entry for an unknown name fails the build. On an Api Studio request the placeholder values become router-context defaults, so every link the bundle generates carries them; elsewhere the host passes them. Requirements set on the host's route import still apply. |
| `ui.locale_scope` | **1.11.** Which requests get the Api Studio UI locale (session key `_nowo_api_studio_locale`): `routes` (default) = Api Studio routes only; `global` = every request of the host (1.10 behaviour); `none` = never (the host owns the locale). |
| `ui.default_locale` | Default UI/documentation locale (default `en`). |
| `ui.locales` | Enabled UI/documentation locales. |
| `ui.request_timeout_seconds` | HTTP client timeout (`timeout` + `max_duration`) for the request console, SOAP included (1–300, default `30`). Innermost deadline for outbound calls; keep PHP/`max_execution_time` and FrankenPHP/Caddy write limits **above** this value (see [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)). |
| `ui.list_page_size` | Rows per page on the workspace list (`?page=N`, UiKit pagination) and maximum workspace cards on the dashboard (1–500, default `50`); dashboard totals use `COUNT` queries (REQ-PERF-001). The sidebar tree still lists every workspace. |
| `ui.required_roles` | Legacy role list used when `security.access_roles` is empty. An empty effective list **denies**; both lists empty fails container compilation unless `security.access_checker` or `security.allow_unauthenticated` is set. |
| `security.access_roles` | Preferred roles for Api Studio UI (user needs at least one). Empty falls back to `ui.required_roles`; an empty effective list **denies** (never "everyone") and fails container compilation unless `security.access_checker` or `security.allow_unauthenticated` is set. Default `ROLE_ADMIN`. |
| `security.access_checker` | Optional service id implementing `ApiStudioAccessCheckerInterface`; `canAccess($user)` is called on every Api Studio route in addition to the role check (anonymous users are denied unless `allow_unauthenticated: true`). |
| CSRF (no key) | With `enabled: true` the container refuses to compile unless `framework.csrf_protection` and `framework.form.csrf_protection` are on (`security.csrf.token_manager` + `form.type_extension.csrf`): delete actions and entity forms rely on form CSRF. |
| `security.allow_unauthenticated` | **DEV/DEMO only.** When `true`, the UI may load without SecurityBundle / without login. Must be a literal boolean (no `%env()%`), requires `kernel.debug` and is refused in the `prod` environment. `enabled` and `execution_url_allowlist_required` must be literal booleans too. |
| `execution_url_allowlist` | Optional allowlist for outbound URLs. Plain entries match the parsed host exactly (`api.example.com`), as a suffix (`.example.com` / `*.example.com`: apex + subdomains), or as a URL pinning scheme/port/path prefix (`https://api.example.com:8443/v1`); `#…#` entries are regexes on the full URL. Empty = any public URL after SSRF checks unless `execution_url_allowlist_required` is true. |
| `execution_max_redirects` | Redirects followed when executing requests (default `0`: 3xx responses are returned as-is). Followed hops are still blocked from private networks but are not re-checked against the allowlist. Applies to the SOAP call and WSDL download too; a cross-host hop drops `Authorization`/`Cookie` but forwards custom headers (e.g. an API key header), see [USAGE.md — SOAP services](USAGE.md#soap-services). |
| `execution_max_response_bytes` | Largest response body read from a remote API, SOAP endpoint or WSDL / XSD document (default 10 MiB, minimum 1024); a bigger transfer is cancelled and the request fails with a clear error. |
| `execution_url_allowlist_required` | When `true`, an empty allowlist fails container compilation (recommended in production). Default `false` for BC. |
| `security.permission_checker` | **1.11.** Service id implementing `ApiStudioPermissionCheckerInterface`: `isGranted(?user, ApiStudioAction, ApiStudioSubject)` and `visibleWorkspaceIds(?user, tenant)`. Not viewable workspaces answer 404, viewable ones without the action 403, and pages hide forbidden actions. Null = everything granted to whoever passes the role / access checks (1.10 behaviour). See [USAGE.md — Tenants and permissions](USAGE.md#tenants-and-permissions). |
| `security.feature_gate` | **1.16.** Service id implementing `ApiStudioFeatureGateInterface::isEnabled(?user, tenant, request)`: whether Api Studio is available for the tenant and user (a plan feature). Consulted on every Api Studio route after the role check, the access checker and `studio.open`, before the controller; `false` answers `access_denied_response`. Null = no gate. See [USAGE.md — Plan gate, 404 policy and host routes](USAGE.md#plan-gate-404-policy-and-host-routes-116). |
| `security.access_denied_response` | **1.16.** `403` (default, unchanged) or `404`: with `404` every refusal on an Api Studio route (permission checker, tenancy, feature gate, role / access checks of a signed-in user, host route denials) answers the same 404 as a genuine not-found (body and cache headers). Literal value (no `%env()%`). |
| `security.host_routes` | **1.16.** Map of host route name => `studio.open` or `workspace.create`: host pages guarded like the bundle's routes (enabled check, tenant, roles, access checker, `studio.open`, feature gate, policy, `no-store`, SQL filter, `ui.path` placeholders). Workspace actions are checked in the host controller (`ApiStudioAuthorization::requireWorkspace()`). Routes starting with `nowo_api_studio_` are refused. |
| `tenancy.enabled` | **1.11.** Workspaces belong to a tenant resolved per request; lists, counts, the sidebar and every lookup are scoped to it; the workspace slug is unique per tenant. Maps `tenant_type` / `tenant_id` (and swaps the slug index): a migration. Literal boolean. Default `false` (no schema change). |
| `tenancy.sql_filter` | **1.11.** With tenancy (default `true`): the Doctrine filter `nowo_api_studio_tenant` narrows every bundle query of an Api Studio request to the tenant and the visible workspaces (defence in depth; the controllers' explicit checks stay the control). **1.17:** with `scope.enabled` it also narrows by scope, and runs with scopes on even without tenancy. |
| `scope.enabled` | **1.17.** Workspaces get an optional host scope below the tenant (e.g. `project:<uuid>`): every lookup, list, count, the sidebar and the SQL filter are narrowed by it (byte-exact), the slug becomes unique per (tenant,) scope, and every action in a scope also needs `scope.access_checker`. Maps `host_scope` (binary collation on MySQL / MariaDB) and swaps the slug index: a migration ([UPGRADING](UPGRADING.md#to-1170)). Literal boolean. Default `false` (no schema, route or query change). |
| `scope.resolver` | **1.17.** Service id implementing `ScopeResolverInterface::resolve(ScopeRouteContext $route, ApiStudioTenant $tenant): ?WorkspaceScope` (required with scopes): the scope of an Api Studio request from its route. On a `scope.path` route null answers 404; on a tenant-level route null means "tenant level". |
| `scope.access_checker` | **1.17.** Service id implementing `ScopeAccessCheckerInterface` (required with scopes; deny by default): `isGranted(?object $user, ApiStudioAction $action, ScopeAccessSubject $subject)` per scope and action, on top of `security.permission_checker`, and `visibleScopes()` for tenant-level lists. |
| `scope.label_provider` | **1.17.** Optional `ScopeLabelProviderInterface`: the badge of scoped workspaces on tenant-level lists (one call per page). Null = the raw scope value. |
| `scope.path` / `scope.path_requirements` | **1.17.** Scoped mount: every Api Studio route also gets a twin under this path, named `nowo_api_studio_scoped_<suffix>`, from the same `routes.yaml` import; the bundle's links stay in the mount. Placeholders follow the `ui.path` rules. Null (default) = no twin routes (a resolver may still return a scope on `ui.path`). |
| `scope.layout_template` | **1.17.** Layout the pages of a scoped request extend (e.g. the host's project page with its tabs). Null = `ui.layout_template`. Pages get the variable `nowo_api_studio_scope`. |
| `scope.tenant_list` | **1.17.** `all` (default): tenant-level pages list unscoped workspaces plus those of `visibleScopes()`, with a badge; `unscoped`: only workspaces without a scope (scoped ones answer 404 there). |
| `concurrency.conflict_status` / `concurrency.retry_after` | **1.17.** Answer of a lock conflict (deadlock, lock wait timeout, serialization failure) on an Api Studio route: `409` (default) or `503`, with `X-Conflict-Code: CONFLICT_001` + `Retry-After` (seconds, default 1), or `CONFLICT_002` without `Retry-After` when part was committed. Domain conflicts are not affected: a stale edit always answers 409 with `X-Error-Code: API_STUDIO_STALE_EDIT` (1.21). See [CONCURRENCY](CONCURRENCY.md). |
| `tenancy.resolver` | **1.11.** Service id implementing `ApiStudioTenantResolverInterface` (required with tenancy). A null tenant answers 404. |
| `tenancy.provider` | **1.12.** Service id implementing `ApiStudioTenantProviderInterface::current(): ?ApiStudioTenant` — a request-free alternative to `tenancy.resolver` for hosts whose application layer already knows the tenant. Set one of the two (both fails the build). |
| `history.scope` | **1.11.** `endpoint` (default): one shared history per endpoint. `user`: rows record who executed them (`executed_by`, a migration) and users see only their own unless granted `history.view_all`. |
| `history.actor_resolver` | **1.11.** Service id implementing `ApiStudioActorResolverInterface` (the opaque actor id, ≤ 64 characters). Default: the user identifier, hashed beyond 64 characters. |
| `history.retention_days` | **1.24.** Request history older than this many days (0–3650, default **90**) is deleted by `nowo:api-studio:history:purge` or the `Privacy\PurgeApiStudioHistory` Messenger message, oldest first, in batches. Nothing is deleted until the host schedules one of them. `0` = keep forever. See [USAGE.md](USAGE.md#personal-data-and-retention-124). |
| `history.purge_batch_size` | **1.24.** Rows deleted per batch (10–5000, default 500) by the purge and the GDPR erasure; each batch is one short transaction. |
| `personal_data.enabled` | **1.24.** With `nowo-tech/user-kit-bundle` installed (default `true`): register the UserKit eraser `api_studio` and the export section `api_studio`. `false` when the application erases / exports the Api Studio tables itself. The ports `ApiStudioPersonalDataEraserInterface` / `ApiStudioPersonalDataExporterInterface` stay registered either way. Literal boolean. |
| `scripts.mode` | **1.11.** `inline` (default): pre / post request scripts run in the page (needs `script-src 'unsafe-eval'`). `disabled`: no script tab and no script code sent to the browser; stored scripts are kept. |
| `scripts.mode: sandbox` | **1.15.** Scripts run in an opaque-origin iframe with its own CSP (no network, no page, cookies or storage); the console page needs `frame-src 'self'` and no `'unsafe-eval'`. The confirmation of `scripts.confirm_stored` is not used in this mode. Enables workspace and folder scripts (two nullable columns on each table, see UPGRADING). |
| `scripts.limits.time_ms` | **1.15.** Wall time of one phase (pre or post, 100–30000 ms, default 2000): the sandbox frame is removed, the server runner's sidecar is killed. |
| `scripts.limits.memory_mb` / `output_kb` | **1.15.** Server runner: memory handed to the sidecar command (`{memory_mb}`, the V8 heap limit by default; 16–1024, default 64) and the most stdout + stderr of one run (16–10240 KB, default 256; killed past it). |
| `scripts.limits.send_requests` | **1.15.** `pm.sendRequest` calls per phase, sandbox frame and server runner (0–100, default 10). |
| `scripts.server_runner` | **1.15.** Server-side runner for unattended runs (collection runner, CLI, monitors): `ApiStudioScriptRunnerInterface`, implemented by `ProcessScriptRunner` (one sidecar process per run running the bundled `Resources/runner/api-script-runner.js`, spoken to over stdin / stdout; environment scrubbed to `PATH`, `HOME`, `TMPDIR`, `LANG`, `DENO_DIR`). The default command is Deno without any permission (no file system, network, environment or subprocess); with Node 25+, use `node --permission --max-old-space-size={memory_mb} {runner}`: the runner refuses every run under Node below 25 or without `--permission` (those do not block the network: SSRF, see SECURITY). Needs `proc_open` (no extra package). Disabled (default): `NullScriptRunner`, scripts reported as not run. A host can alias the interface to its own runner. `ScriptChainBuilder` gives the scripts of an endpoint in run order (workspace and folder levels only in sandbox mode). |
| `scripts.confirm_stored` | **1.13.2.** `true` (default): with `inline`, before Send runs a stored service or endpoint script that this browser session has not approved, the console shows the code in a dialog: *Run with scripts* (the approval is kept in `sessionStorage` per SHA-256 of the code, so a changed script is asked again), *Send without scripts* (no script runs) or *Cancel*. Code typed by the user in an empty script field is not asked. Without Web Crypto (plain HTTP other than localhost) every send asks. `false`: stored scripts run without asking (1.13.1 behaviour). |
| `search_kit.enabled` | **Unreleased.** Adds the Api Studio groups to SearchKit's header search when `nowo-tech/search-kit-bundle` `^1.9` is installed (default `false`: opt-in; ignored when `enabled: false`). A literal boolean (no `%env()%`). Turn it on once any host guard of the studio routes (organisation membership, a plan) is mirrored in an `ApiStudioSearchContextEvent` listener: that guard does not run on a search request. Results contain only what the user may open and never a base URL, header, auth value, body, script or variable. See [USAGE.md](USAGE.md#global-search). |
| `search_kit.groups.workspaces` / `.services` / `.endpoints` | **Unreleased.** Each group on or off (default `true`). Workspaces and services match by name, endpoints by name, path and HTTP method. |
| `folders.enabled` | **1.14.** Nested folders in each service: tree with drag & drop, auth and headers inherited from the folders, folder-aware Postman / OpenAPI import and OpenAPI export. Maps the `folder` table and `endpoint.folder_id` (a migration, see [UPGRADING](UPGRADING.md#to-1140)). Default `false`: the 1.13 schema, pages and importers. |
| `folders.max_depth` | **1.14.** Deepest folder level (1–32, default `8`; a top-level folder has depth 1). Checked on create, move and import (deeper imported folders are merged into their deepest allowed parent). |
| `security.folder_permission_checker` | **1.14.** Service id implementing `ApiStudioFolderPermissionCheckerInterface` (with `folders.enabled`): folder-level sharing on top of the workspace decisions. It can only deny: hidden folders and their endpoints answer 404 and are left out of the tree, lists and exports; denied actions inside a visible folder answer 403. Null = folders follow their workspace. |
| `secrets.encrypt` | Encrypt environment variables marked `secret` at rest with sodium (default `true`). |
| `secrets.encryption_key` | Optional key material; defaults to `kernel.secret` when null. Changing it (or `kernel.secret`) makes the stored secrets unreadable: they fail closed (never sent or exported, shown as *Needs re-entry*). `nowo:api-studio:secrets:status` lists them. |
| `secrets.source` | **1.13.** Service id implementing `Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface` (default `null`). A secret variable whose value is `{{secret:<source>/<id>}}` is then resolved on the server at execute time, per user and workspace as the host decides; the value never reaches the browser, the history, logs or exports, and a request that needs an unresolved reference is not sent (422 `secret_unresolved`). `null` = such values are ordinary secret values (1.12 behaviour). See [USAGE.md](USAGE.md#host-secrets-113). |

### Host `access_control` example

```yaml
# config/packages/security.yaml (host app)
security:
    access_control:
        - { path: ^/api-studio, roles: ROLE_ADMIN }
```

Environment variables use `{{variable_name}}` syntax in URLs, headers, and bodies.

### Table prefix example

```yaml
nowo_api_studio:
    table_prefix: acme_api_
```

Creates tables such as `acme_api_workspace`, `acme_api_endpoint`, etc. After changing the prefix on an existing database, run `nowo:api-studio:sync-schema` (or migrate manually).

### Embedding in the host layout (REQ-UI-001 / REQ-UI-003)

Api Studio pages extend the UiKit page scaffolds; the host chooses the shell and the look once, in UiKit, for every UiKit
bundle (no Api Studio template to copy):

```yaml
# config/packages/nowo_ui_kit.yaml (host app)
nowo_ui_kit:
    css_framework: bootstrap5          # bootstrap5 | bootstrap4 | tailwind | foundation | tabler | custom | none
    icon_set: bootstrap-icons
    layouts:
        admin: 'layouts/admin.html.twig' # your layout or a one-file bridge rendering the UiKit canonical blocks
    theme:
        tokens: { primary: '#7c3aed' }   # --nowo-ui-* tokens (Api Studio maps its workbench colours to them)
        bundles:
            NowoApiStudioBundle: { classes: { table: 'table table-sm' } }   # optional per-bundle exception
```

A one-file bridge over the host base layout (UiKit 2.11+ `_bridge.html.twig`):

```twig
{# templates/layouts/admin.html.twig #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}{{ parent() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ include(nowo_ui_partial('scripts')) }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

Framework choices, all without touching Api Studio templates:

| `nowo_ui_kit.css_framework` | Result on the Api Studio pages |
| --- | --- |
| `bootstrap5` / `bootstrap4` | Theme slots print Bootstrap classes (buttons, tables, cards, badges); load Bootstrap in your layout |
| `tailwind` | Theme slots print Tailwind utilities; build them in your Tailwind pipeline |
| `foundation` | Theme slots print Foundation classes |
| `custom` / `none` | Only the semantic `nowo-ui-*` classes (UiKit `nowo-ui.css`) — style them in your CSS or remap with `theme.classes` |

`ui.layout_template` stays available for an Api Studio-only layout (it wins over `nowo_ui_kit.layouts.admin`). Api
Studio assets (`api-studio-theme.css`, scripts) stack in `nowo_ui_styles` / `nowo_ui_scripts` after `{{ parent() }}`.
Row actions follow `nowo_ui_kit.row_actions_display` (`icon` | `text` | `icon_text`); confirm dialogs and modals are
UiKit `<dialog>` elements (no inline handlers, strict CSP friendly). See [TEMPLATES.md](TEMPLATES.md).

### Forms (FormKit profile `api_studio`)

Every Api Studio form type is a FormKit type bound to the profile `api_studio` (REQ-TWIG-005 rule 11). The bundle
prepends that profile (translation domain `NowoApiStudioBundle`, the semantic `nowo-ui-input` class, help / placeholder
keys only when translated) unless the host defines it. Override it in the host instead of forking templates:

```yaml
# config/packages/nowo_form_kit.yaml (host app)
nowo_form_kit:
    profiles:
        api_studio:
            alias: api_studio
            translation_domain: NowoApiStudioBundle
            defaults:
                attr: { class: 'nowo-ui-input form-control' }
```

The form theme follows the host / UiKit (`nowo_ui_kit.css_framework`); the pages set no `form_themes` of their own.
