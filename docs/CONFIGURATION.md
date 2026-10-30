# Configuration

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    enabled: true
    connection: default
    table_prefix: api_studio_
    environments: [dev, test, prod]
    ui:
        path: '/api-studio'
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
    execution_url_allowlist_required: false
    execution_url_allowlist: []
    execution_max_redirects: 0
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
| `ui.default_locale` | Default UI/documentation locale (default `en`). |
| `ui.locales` | Enabled UI/documentation locales. |
| `ui.request_timeout_seconds` | HTTP client timeout (`timeout` + `max_duration`) for the request console, SOAP included (1–300, default `30`). Innermost deadline for outbound calls; keep PHP/`max_execution_time` and FrankenPHP/Caddy write limits **above** this value (see [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)). |
| `ui.list_page_size` | Rows per page on the workspace list (`?page=N`, UiKit pagination) and maximum workspace cards on the dashboard (1–500, default `50`); dashboard totals use `COUNT` queries (REQ-PERF-001). The sidebar tree still lists every workspace. |
| `ui.required_roles` | Legacy role list used when `security.access_roles` is empty. An empty effective list **denies**; both lists empty fails container compilation unless `security.access_checker` or `security.allow_unauthenticated` is set. |
| `security.access_roles` | Preferred roles for Api Studio UI (user needs at least one). Empty falls back to `ui.required_roles`; an empty effective list **denies** (never "everyone") and fails container compilation unless `security.access_checker` or `security.allow_unauthenticated` is set. Default `ROLE_ADMIN`. |
| `security.access_checker` | Optional service id implementing `ApiStudioAccessCheckerInterface`; `canAccess($user)` is called on every Api Studio route in addition to the role check (anonymous users are denied unless `allow_unauthenticated: true`). |
| CSRF (no key) | With `enabled: true` the container refuses to compile unless `framework.csrf_protection` and `framework.form.csrf_protection` are on (`security.csrf.token_manager` + `form.type_extension.csrf`): delete actions and entity forms rely on form CSRF. |
| `security.allow_unauthenticated` | **DEV/DEMO only.** When `true`, the UI may load without SecurityBundle / without login. Production **must** keep `false` (default). |
| `execution_url_allowlist` | Optional allowlist for outbound URLs. Plain entries match the parsed host exactly (`api.example.com`), as a suffix (`.example.com` / `*.example.com`: apex + subdomains), or as a URL pinning scheme/port/path prefix (`https://api.example.com:8443/v1`); `#…#` entries are regexes on the full URL. Empty = any public URL after SSRF checks unless `execution_url_allowlist_required` is true. |
| `execution_max_redirects` | Redirects followed when executing requests (default `0`: 3xx responses are returned as-is). Followed hops are still blocked from private networks but are not re-checked against the allowlist. Applies to the SOAP call and WSDL download too; a cross-host hop drops `Authorization`/`Cookie` but forwards custom headers (e.g. an API key header), see [USAGE.md — SOAP services](USAGE.md#soap-services). |
| `execution_url_allowlist_required` | When `true`, an empty allowlist fails container compilation (recommended in production). Default `false` for BC. |
| `secrets.encrypt` | Encrypt environment variables marked `secret` at rest with sodium (default `true`). |
| `secrets.encryption_key` | Optional key material; defaults to `kernel.secret` when null. |

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
