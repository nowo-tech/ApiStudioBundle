# Implementation Plan: Published docs portal per workspace

**Branch**: `010-docs-portal` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.22.0** (minor, opt-in)

## Configuration

```yaml
nowo_api_studio:
    docs_portal:
        enabled: false
        path_prefix: /api-docs            # public; container parameter for security.yaml
        access: null                      # service id implementing ApiStudioDocsPortalAccessInterface
        brand_provider: null              # optional BrandContracts bridge service id
```

## Data model and migrations

`{prefix}docs_portal`: `id`, `workspace_id` FK unique cascade, `slug` varchar(128) unique **binary NO PAD collation**,
`visibility` varchar(16), `title` varchar(255) nullable, `intro` text nullable, `published` bool, `updated_at`.
Mapped only with `docs_portal.enabled`. UPGRADING DDL.

## Architecture

- `DocsPortal/DocsPortalRouteLoader` (public routes under the prefix, `/v1/`).
- `DocsPortal/DocsPortalAccess` → host port `ApiStudioDocsPortalAccessInterface` with `DocsPortalRef` VO (ids only).
- `DocsPortal/DocsPortalViewBuilder` (stateless): builds view models from repositories with the folder filter of spec
  002 and the OpenAPI exporter (secret-free).
- `Controller/DocsPortalController` (UiKit public scaffold pages) and `DocsPortalAdminController` (workspace manage
  page: publish, slug, visibility, title, intro).
- Branding: UiKit theme by default; optional BrandContracts bridge (opt-in, owner decision spec 220).

## BC analysis

Additive and opt-in; the public route exists only when enabled. No new `ApiStudioAction` case (managing uses
`workspace.manage`; reading uses the separate portal access port so existing checkers are untouched). No BC break.

## FrankenPHP worker mode (kernel reset false)

View builder and access adapter are stateless; brand data resolved per request through the provider port; no static
caches. Worker test: 2,000 renders, two tenants, flat memory.
