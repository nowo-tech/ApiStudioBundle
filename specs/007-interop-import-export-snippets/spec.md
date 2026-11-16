# Feature Specification: Interop — imports, Postman export, code snippets

**Feature Branch**: `007-interop-import-export-snippets`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.19.0 (minor, additive)
**Roadmap item**: 6 of 10
**Input**: "Import cURL, HAR, Insomnia and WSDL (one endpoint per operation). Export Postman v2.1. Code snippets for
fetch, PHP, Python and Go."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Import cURL (Priority: P1)

A user pastes one or more `curl` commands; each becomes an endpoint (method, URL split into base URL + path + query,
headers, body, basic auth `-u`, `--data*`, `-F` form fields as a documented body).

**Acceptance Scenarios**:

1. **Given** `curl -X POST https://api.test/v1/users -H 'Content-Type: application/json' -d '{"a":1}'`, **Then** a POST
   endpoint `/v1/users` with that header and body is created in the chosen service (or a new service for the host).
2. **Given** shell syntax the parser does not support (`$(…)`, pipes, env expansion), **Then** it is rejected, never
   executed, with the offending token reported.
3. **Given** credentials in the command (`-u user:pass`, `Authorization` header), **Then** they become secret variables
   in the chosen environment, not plain endpoint headers.

### User Story 2 - Import HAR and Insomnia (Priority: P2)

HAR 1.2 entries (deduplicated by method + path) and Insomnia v4 exports (workspaces, request groups → folders,
environments → environments) become endpoints, folders (spec 002) and environments.

**Acceptance Scenarios**: HAR responses become response examples (bodies truncated to the import limit); cookies and
`Authorization` values are dropped unless the user ticks "keep credentials as secrets".

### User Story 3 - Import WSDL (Priority: P1)

A WSDL (uploaded or fetched through `GuardedWsdlLoader`) becomes a SOAP service with one endpoint per operation (per
binding / port), SOAPAction set, and a request envelope template generated from the schema (XSD types, depth-limited).

**Acceptance Scenarios**:

1. **Given** a WSDL with imports, **Then** imports are fetched through the guarded loader only (SSRF guard, allow-list,
   size limit), XXE disabled.
2. **Given** SOAP 1.1 and 1.2 bindings, **Then** the user picks one; the endpoint content type follows the binding.

### User Story 4 - Export Postman v2.1 (Priority: P1)

A service (or folder) exports as a Postman v2.1 collection: folders as `item` groups, auth per level, headers, bodies,
saved examples as `response[]`, variables as collection variables **without secret values** (secrets exported as
empty with a description), scripts as `event[]` (only with `workspace.edit_scripts`).

**Acceptance Scenarios**: importing the exported file back (spec 002 importer) gives the same tree; the export
validates against the Postman v2.1 JSON schema.

### User Story 5 - Code snippets (Priority: P2)

The console shows a "Code" panel generating the current request (after variable resolution of non-secret variables)
as JavaScript `fetch`, PHP (Symfony HttpClient and cURL), Python `requests` and Go `net/http`.

**Acceptance Scenarios**: secret variables appear as `{{name}}` placeholders / environment lookups, never values;
snippets are generated client-side from the same data the console already holds (no new data leaves the server).

## Requirements *(mandatory)*

- **FR-INT-001**: `Import/CurlCommandParser` (tokeniser, no shell evaluation), `HarImporter`, `InsomniaImporter`,
  `WsdlImporter` behind the existing `DocumentParser` detection; import UI gains the formats.
- **FR-INT-002**: `PostmanCollectionExporter` (v2.1 schema), route under the import / export hub,
  `workspace.export` (variables without values; with `workspace.export_variables`, values of non-secret variables).
- **FR-INT-003**: `api-code-snippets.ts` generators (pure functions, Vitest snapshot tests).
- **FR-INT-004**: Import limits: file size, entries (`import.max_entries`, default 5,000), schema depth for WSDL.
- **FR-INT-005**: Every remote fetch (WSDL URL, HAR / Insomnia never fetch) goes through the guarded client.

## Success Criteria *(mandatory)*

- **SC-001**: Round trip Postman export → import keeps the tree, auth and examples (functional test).
- **SC-002**: The cURL parser fuzz test (10,000 random inputs) never executes or hangs.
- **SC-003**: Snippets for a request with a secret never contain the value (Vitest).
