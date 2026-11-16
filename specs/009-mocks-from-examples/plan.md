# Implementation Plan: Mocks from saved examples

**Branch**: `009-mocks-from-examples` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.21.0** (minor, opt-in)

## Configuration

```yaml
nowo_api_studio:
    mocks:
        enabled: false
        path_prefix: /api-studio-mock     # public; serves <path_prefix>/v1/{mockKey}/…
        rate_limit: 60/minute
        cors_allowed_origins: []
```

`symfony/rate-limiter` becomes a `suggest`; enabling mocks without it fails container compilation with a clear message.

## Data model and migrations

| Table | Change |
| --- | --- |
| `{prefix}service` | `mock_enabled` bool default false, `mock_key_hash` char(64) nullable unique (**binary collation**), `mock_key_hint` varchar(8) nullable |
| `{prefix}response_example` | `is_default` bool default false, `headers` json default `[]` |

Mapped only with `mocks.enabled` (metadata listener). UPGRADING DDL.

## Architecture

- `Mock/MockRouteLoader` registers the public route under `path_prefix` (container parameter
  `nowo_api_studio.mocks.path_prefix` so host `security.yaml` can reference it).
- `Mock/MockResolver` (stateless): key hash → service (tenant scope applied from the service's workspace), method +
  template matching (pre-compiled per request, not cached in properties), example selection.
- `Controller/MockController`: rate limiter (`RateLimiterFactory` service, storage cache pool), CORS, masking, no
  cookies / session (`Cache-Control: no-store`).
- Studio UI: service page "Mock" panel (enable, rotate key, copy URL).

## BC analysis

Additive and opt-in; new columns mapped only when enabled. Public route only exists when enabled. No BC break.

## FrankenPHP worker mode (kernel reset false)

Resolver and controller keep no per-request data; the rate limiter state lives in the cache pool, never in service
properties. Worker test: 5,000 requests, two keys, flat memory, no cross-key example leakage.
