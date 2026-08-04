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
        layout_template: '@NowoApiStudioBundle/layout.html.twig'
        css_framework: custom
        default_locale: en
        locales: [en, es, fr, it]
        request_timeout_seconds: 30
        # Legacy fallback when security.access_roles is empty
        required_roles: [ROLE_ADMIN]
    security:
        access_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
        # access_checker: App\Security\MyApiStudioAccessChecker
    execution_url_allowlist_required: false
    execution_url_allowlist: []
    # Examples:
    # execution_url_allowlist_required: true
    # execution_url_allowlist:
    #   - api.example.com
    #   - '#^https://staging\.example\.com/#'
    secrets:
        encrypt: true
        # encryption_key: '%env(NOWO_API_STUDIO_SECRETS_KEY)%'
```

| Option | Description |
|--------|-------------|
| `enabled` | Enable or disable the bundle (default `true`). |
| `connection` | Doctrine connection name used by sync-schema (default `default`). |
| `table_prefix` | Prefix for all bundle tables and unique indexes (default `api_studio_`). Lowercase letters, numbers, and underscores only. |
| `environments` | Default environment names for new workspaces (default `dev`, `test`, `prod`). |
| `ui.path` | Dashboard base path / path prefix (default `/api-studio`). Lock with Symfony `access_control`. |
| `ui.layout_template` | Twig layout extended by `@NowoApiStudioBundle/base.html.twig` (global `nowo_api_studio_layout_template`). Default is the bundle shell. Point to your app layout or a bridge that extends it to embed Api Studio in host chrome (REQ-UI-001). |
| `ui.css_framework` | `bootstrap5` \| `tailwind` \| `foundation` \| `custom` (default). `custom` loads Tabler CDN + `as-*` / `nowo-ui-*` classes. Other values skip Tabler and expect host styles. When UiKit is present and `nowo_ui_kit` is not set by the host, Api Studio seeds `nowo_ui_kit.css_framework` / `icon_set` from this value (REQ-UI-001-kit). |
| `ui.default_locale` | Default UI/documentation locale (default `en`). |
| `ui.locales` | Enabled UI/documentation locales. |
| `ui.request_timeout_seconds` | HTTP client / SOAP connection timeout for the request console (1–300, default `30`). Innermost deadline for outbound calls; keep PHP/`max_execution_time` and FrankenPHP/Caddy write limits **above** this value (see [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)). |
| `ui.required_roles` | Legacy role list used when `security.access_roles` is empty. Empty disables bundle-level checks. |
| `security.access_roles` | Preferred roles for Api Studio UI (user needs at least one). Empty disables bundle-level role checks. Default `ROLE_ADMIN`. |
| `security.access_checker` | Optional service id implementing `ApiStudioAccessCheckerInterface`. |
| `security.allow_unauthenticated` | **DEV/DEMO only.** When `true`, the UI may load without SecurityBundle / without login. Production **must** keep `false` (default). |
| `execution_url_allowlist` | Optional allowlist for outbound URLs (substring or `#regex`). Empty = any public URL after SSRF checks unless `execution_url_allowlist_required` is true. |
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

### Embedding in the host layout (REQ-UI-001)

1. Set `ui.layout_template` to a Twig template that extends your project layout (e.g. `base.html.twig`) and maps Api Studio blocks (`body`, `topbar_title`, `stylesheets`, `javascripts`).
2. Bundle pages extend `@NowoApiStudioBundle/base.html.twig`, which extends that layout and stacks **UiKit** (`nowo-ui.css`) plus Api Studio CSS/JS with `{{ parent() }}`.
3. In your bridge (or overrides of `stylesheets` / `javascripts`), keep `{{ parent() }}` so host and package assets still load.
4. Or override `templates/bundles/NowoApiStudioBundle/layout.html.twig` / `base.html.twig` as needed.
