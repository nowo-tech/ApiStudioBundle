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
        default_locale: en
        locales: [en, es, fr, it]
        request_timeout_seconds: 30
        # Legacy fallback when security.access_roles is empty
        required_roles: [ROLE_ADMIN]
    security:
        access_roles: [ROLE_ADMIN]
        # access_checker: App\Security\MyApiStudioAccessChecker
    execution_url_allowlist: []
    # Examples:
    # execution_url_allowlist:
    #   - api.example.com
    #   - '#^https://staging\.example\.com/#'
```

| Option | Description |
|--------|-------------|
| `enabled` | Enable or disable the bundle (default `true`). |
| `connection` | Doctrine connection name used by sync-schema (default `default`). |
| `table_prefix` | Prefix for all bundle tables and unique indexes (default `api_studio_`). Lowercase letters, numbers, and underscores only. |
| `environments` | Default environment names for new workspaces (default `dev`, `test`, `prod`). |
| `ui.path` | Dashboard base path (default `/api-studio`). |
| `ui.default_locale` | Default UI/documentation locale (default `en`). |
| `ui.locales` | Enabled UI/documentation locales. |
| `ui.request_timeout_seconds` | HTTP client timeout for the request console (1–300, default `30`). |
| `ui.required_roles` | Legacy role list used when `security.access_roles` is empty. Empty disables bundle-level checks. |
| `security.access_roles` | Preferred roles for Api Studio UI (user needs at least one). Empty disables bundle-level checks. Default `ROLE_ADMIN`. |
| `security.access_checker` | Optional service id implementing `ApiStudioAccessCheckerInterface`. |
| `execution_url_allowlist` | Optional allowlist for outbound URLs (substring or `#regex`). Empty = any public URL after SSRF checks. |

Environment variables use `{{variable_name}}` syntax in URLs, headers, and bodies.

### Table prefix example

```yaml
nowo_api_studio:
    table_prefix: acme_api_
```

Creates tables such as `acme_api_workspace`, `acme_api_endpoint`, etc. After changing the prefix on an existing database, run `nowo:api-studio:sync-schema` (or migrate manually).
