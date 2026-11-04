# Configuration

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    enabled: true
    connection: default
    table_prefix: api_studio_
    environments: [dev, test, prod]
    ui:
        path: '/api-studio'      # may hold {placeholders} since 1.11, e.g. '/organizations/{organization}/api-studio'
        path_requirements: {}    # e.g. {organization: '[0-9a-f-]{36}'}; default [^/]+
        locale_scope: routes     # routes (1.11 default) | global (1.10 behaviour) | none
        layout_template: null    # null = nowo_ui_kit.layouts.admin (UiKit page contract)
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
    tenancy:                     # 1.11
        enabled: false           # literal boolean; maps tenant_type / tenant_id on the workspace table
        # resolver: App\ApiStudio\MyTenantResolver               # required when enabled
        sql_filter: true         # defence-in-depth Doctrine filter during Api Studio requests
    history:
        scope: endpoint          # endpoint | user (maps executed_by)
        # actor_resolver: App\ApiStudio\MyActorResolver
    scripts:
        mode: inline             # inline | disabled
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
```

| Option | Description |
|--------|-------------|
| `enabled` | Enable or disable the bundle (default `true`). When `false`, every Api Studio route (`nowo_api_studio_*`) answers 404. |
| `connection` | Doctrine connection name used by sync-schema (default `default`). |
| `table_prefix` | Prefix for all bundle tables and unique indexes (default `api_studio_`). Lowercase letters, numbers, and underscores only. |
| `environments` | Environments created automatically when a workspace is created from the UI (default `dev`, `test`, `prod`); the first one is marked as default. Slugs are derived from the names (lowercase, non-alphanumerics → `_`). Set `[]` to create workspaces without environments. Imports and the demo seeder are not affected. |
| `ui.path` | Dashboard base path / path prefix (default `/api-studio`). Lock with Symfony `access_control`. |
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
| `tenancy.enabled` | **1.11.** Workspaces belong to a tenant resolved per request; lists, counts, the sidebar and every lookup are scoped to it; the workspace slug is unique per tenant. Maps `tenant_type` / `tenant_id` (and swaps the slug index): a migration. Literal boolean. Default `false` (no schema change). |
| `tenancy.sql_filter` | **1.11.** With tenancy (default `true`): the Doctrine filter `nowo_api_studio_tenant` narrows every bundle query of an Api Studio request to the tenant and the visible workspaces (defence in depth; the controllers' explicit checks stay the control). |
| `tenancy.resolver` | **1.11.** Service id implementing `ApiStudioTenantResolverInterface` (required with tenancy). A null tenant answers 404. |
| `history.scope` | **1.11.** `endpoint` (default): one shared history per endpoint. `user`: rows record who executed them (`executed_by`, a migration) and users see only their own unless granted `history.view_all`. |
| `history.actor_resolver` | **1.11.** Service id implementing `ApiStudioActorResolverInterface` (the opaque actor id, ≤ 64 characters). Default: the user identifier, hashed beyond 64 characters. |
| `scripts.mode` | **1.11.** `inline` (default): pre / post request scripts run in the page (needs `script-src 'unsafe-eval'`). `disabled`: no script tab and no script code sent to the browser; stored scripts are kept. |
| `secrets.encrypt` | Encrypt environment variables marked `secret` at rest with sodium (default `true`). |
| `secrets.encryption_key` | Optional key material; defaults to `kernel.secret` when null. Changing it (or `kernel.secret`) makes the stored secrets unreadable: they fail closed (never sent or exported, shown as *Needs re-entry*). `nowo:api-studio:secrets:status` lists them. |

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
