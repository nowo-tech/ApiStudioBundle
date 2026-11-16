# Implementation Plan: Real GraphQL

**Branch**: `008-graphql` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.20.0** (minor)

## Configuration

```yaml
nowo_api_studio:
    graphql:
        schema_snapshots: false     # maps the schema table (migration)
        max_schema_bytes: 5242880
```

## Data model and migrations

| Table | Change |
| --- | --- |
| `{prefix}endpoint` | `graphql_variables` text nullable, `graphql_operation_name` varchar(255) nullable |
| `{prefix}graphql_schema` (new) | `id`, `service_id` FK cascade, `environment_id` FK nullable cascade, `source` varchar(16), `schema_json` longtext, `sdl` longtext nullable, `size_bytes` int, `fetched_at` |

One switch, `graphql.schema_snapshots`, maps both endpoint columns and the schema table. With it off, the console
still sends `{query, variables, operationName}` from the editor, but variables and the operation name are not
persisted, so hosts that do not opt in need no schema change. UPGRADING gives the DDL.

## Architecture

- `RequestExecutor::executeGraphql()` builds `{query, variables?, operationName?}`; a bare 1.13 body keeps the current
  payload.
- `Graphql/IntrospectionService` (stateless), `Graphql/SdlConverter` (webonyx optional), `Graphql/SdlImporter`.
- Console: GraphQL tabs (Query / Variables) replace Body for GraphQL services; `api-graphql-editor.ts`.
- Schema browser panel on the service page.

## BC analysis

Body format unchanged for existing endpoints (test pins it). New columns and table opt-in. `webonyx/graphql-php` is a
`suggest`. No BC break.

## FrankenPHP worker mode (kernel reset false)

Snapshots are read from the DB per request (no static schema cache); parsed schemas are request-local. If a parsed
schema cache is added it is a `ResetInterface` memo cleared at `kernel.request` / `kernel.finish_request`, with a test.
Worker test: 500 introspections for two tenants, flat memory.
