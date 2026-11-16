# Feature Specification: Real GraphQL

**Feature Branch**: `008-graphql`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.20.0 (minor)
**Roadmap item**: 7 of 10
**Input**: "Real GraphQL: query and variables editor, introspection, SDL import and export."

## Context

1.13 sends GraphQL as a POST with `{query}` built from the endpoint body; there are no variables, no operation name,
no schema awareness.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Query, variables and operation name (Priority: P1)

A GraphQL endpoint has a query editor, a JSON variables editor (with `{{var}}` resolution inside values) and an
optional operation name; the request body is `{query, variables, operationName}`.

**Acceptance Scenarios**:

1. **Given** an existing 1.13 GraphQL endpoint whose body is a bare query, **Then** it keeps working (the body is used
   as `query`, variables empty).
2. **Given** invalid JSON in variables, **Then** Send is blocked with the body-tools error, like JSON bodies today.
3. **Given** a response with `errors[]` and HTTP 200, **Then** the console flags the GraphQL errors.

### User Story 2 - Introspection (Priority: P1)

The user fetches the schema from the service URL (introspection query through `RequestExecutor`, guarded client,
same auth as the service); the schema snapshot is stored per service and environment and powers autocompletion /
validation in the editor and a schema browser.

**Acceptance Scenarios**:

1. **Given** introspection disabled on the server, **Then** a clear message; the user can import SDL instead.
2. **Given** a stored snapshot, **Then** the editor validates the query locally (unknown field warnings) without
   network calls.
3. **Given** a huge schema, **Then** the snapshot is size-limited (`graphql.max_schema_bytes`, default 5 MB).

### User Story 3 - SDL import and export (Priority: P2)

Import an SDL file (or introspection JSON) to create a GraphQL service with one endpoint per root operation field
(query / mutation; subscriptions listed, not executable until spec 011), with generated query templates and variables.
Export the stored snapshot as SDL.

## Requirements *(mandatory)*

- **FR-GQL-001**: `ApiEndpoint` gains `graphqlVariables` (text, nullable) and `graphqlOperationName` (varchar 255,
  nullable); the query stays in `requestBodyTemplate` (BC).
- **FR-GQL-002**: Entity `ApiGraphqlSchema` (service, environment nullable, sdl text, fetched_at, source
  introspection / sdl, size).
- **FR-GQL-003**: Introspection through `RequestExecutor` (SSRF guard, allow-list, auth, size limit).
- **FR-GQL-004**: SDL parse / print via `webonyx/graphql-php` (suggested dependency, required only for GraphQL schema
  features; without it introspection is stored as JSON and SDL import / export are hidden).
- **FR-GQL-005**: Editor: lightweight TS module (no CDN), syntax highlight via the existing code editor class,
  completion from the snapshot's type map.

## Success Criteria *(mandatory)*

- **SC-001**: 1.13 GraphQL endpoints send byte-identical bodies when variables are empty (regression test).
- **SC-002**: Introspection of the demo GraphQL API stores a snapshot and completion lists its root fields (Playwright).
