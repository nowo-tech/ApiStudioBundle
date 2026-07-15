# Configuration

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    enabled: true
    connection: default
    table_prefix: api_studio_
    ui:
        path: '/api-studio'
        default_locale: en
        locales: [en, es, fr, it]
        request_timeout_seconds: 30
```

| Option | Description |
|--------|-------------|
| `table_prefix` | Prefix for all bundle tables and unique indexes (default `api_studio_`). Use lowercase letters, numbers, and underscores only. |
| `connection` | Doctrine connection name used by sync-schema |
| `ui.path` | Dashboard base path |
| `ui.locales` | Enabled UI/documentation locales |
| `ui.request_timeout_seconds` | HTTP client timeout for the request console |

Environment variables use `{{variable_name}}` syntax in URLs, headers, and bodies.

### Table prefix example

```yaml
nowo_api_studio:
    table_prefix: acme_api_
```

Creates tables such as `acme_api_workspace`, `acme_api_endpoint`, etc. After changing the prefix on an existing database, run `nowo:api-studio:sync-schema` (or migrate manually).
