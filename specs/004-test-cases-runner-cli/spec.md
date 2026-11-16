# Feature Specification: Test cases, collection runner and CLI

**Feature Branch**: `004-test-cases-runner-cli`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.16.0 (minor, additive)
**Roadmap item**: 3 of 10
**Depends on**: 002 (folders), 003 (server-side runner for scripts; declarative assertions do not need it)
**Input**: "Declarative assertions: status, header, JSONPath, XPath for SOAP, JSON schema. Runs a folder or service and
produces a report. A newman-like `api-studio:run` console command for CI."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Declarative assertions on an endpoint (Priority: P1)

An editor attaches assertions to an endpoint (or a saved request example): status equals / in range, header exists /
equals / matches, JSONPath equals / exists / count / matches, XPath (SOAP / XML) equals / exists, response time below
N ms, body matches a JSON Schema (draft 2020-12). The console shows pass / fail per assertion after Send.

**Why this priority**: assertions without code are what most API tests need and they run anywhere (PHP), no script
runtime required.

**Independent Test**: endpoint with `status == 200`, `$.items.length > 0`, schema; a mocked 500 fails two assertions
with readable messages.

**Acceptance Scenarios**:

1. **Given** assertions on an endpoint, **When** the request runs (console or runner), **Then** each assertion is
   evaluated server-side on the masked response and reported (name, expected, actual excerpt, pass / fail).
2. **Given** an invalid JSONPath / XPath / schema, **Then** saving is refused with a translated error.
3. **Given** a secret in the response, **Then** actual-value excerpts are masked like the history.

### User Story 2 - Collection runner (Priority: P1)

A user runs a folder or a service (all endpoints in tree order, optionally a selected subset, N iterations, an
environment, an optional data file CSV / JSON for iterations, delay between requests) and gets a report.

**Independent Test**: run a folder of 3 endpoints with 2 iterations; the report lists 6 executions and their
assertions; a JUnit export validates against the JUnit XSD.

**Acceptance Scenarios**:

1. **Given** `workspace.run`, **When** a run starts from the UI, **Then** it executes sequentially through
   `RequestExecutor` (SSRF guard, allow-list, limits), applies variable changes of scripts (server runner, spec 003) or
   skips scripts with a "not run" note, and stores a run report.
2. **Given** a long run, **Then** the UI shows progress (polling or Mercure when the host has it) and can cancel.
3. **Given** a run report, **Then** it can be downloaded as JSON, JUnit XML and HTML; it never contains secret values.
4. **Given** a user without `workspace.run`, **Then** run actions are hidden and answer 403.

### User Story 3 - CLI for CI (Priority: P1)

`bin/console nowo:api-studio:run <workspace-slug> [--service=] [--folder=] [--environment=] [--iterations=]
[--data=file] [--reporter=cli,json,junit] [--bail] [--timeout-request=] [--tenant-type= --tenant-id=]` runs like
newman and exits non-zero on failures. `api-studio:run` is an alias.

**Acceptance Scenarios**:

1. **Given** a failing assertion, **Then** exit code 1; usage / configuration errors exit 2.
2. **Given** `--reporter=junit --output=report.xml`, **Then** a JUnit file is written.
3. **Given** `execution_url_allowlist_required` semantics, **Then** the CLI refuses to run with an empty allow-list
   unless `--allow-empty-allowlist` is passed outside prod.
4. **Given** tenancy, **Then** the workspace is resolved within the tenant passed by option (same as other commands).

### Edge Cases

- Endpoints with stored scripts and no server runner: scripts reported "not run", assertions still evaluated.
- Data file with more rows than `runner.max_iterations` (default 1,000): refused.
- Response larger than `execution_max_response_bytes`: assertion on body fails with "response too large".

## Requirements *(mandatory)*

- **FR-TEST-001**: Entity `ApiAssertion` (endpoint or request example, type, target expression, operator, expected,
  enabled, sortOrder).
- **FR-TEST-002**: Evaluators: status, header, JSONPath (own small evaluator or `softcreatr/jsonpath` suggested), XPath
  via `DOMXPath` with XXE-safe loading (`LIBXML_NONET`, no entity substitution), JSON Schema via
  `opis/json-schema` (suggested dependency; required only when schema assertions are used), response time.
- **FR-TEST-003**: `Runner/CollectionRunner` (stateless) producing `RunReport` VOs; persistence `ApiRun` +
  `ApiRunResult` (retention `runner.retention_days`, default 30, purge command).
- **FR-TEST-004**: Reporters: CLI table, JSON, JUnit XML, HTML (self-contained, no external assets).
- **FR-TEST-005**: Console command `nowo:api-studio:run` (+ alias `api-studio:run`).
- **FR-TEST-006**: Run UI: "Run folder" / "Run service" (UiKit form scaffold for options), report page (UiKit show).
- **FR-TEST-007**: Runs require `workspace.run`; editing assertions requires `workspace.edit`. No new
  `ApiStudioAction` case (existing host checkers must deny unknown cases, so a new case would silently disable runs).
- **FR-TEST-008**: Events `ApiRunCompletedEvent` (ids + pass / fail counts) after commit.

## Success Criteria *(mandatory)*

- **SC-001**: CI example in USAGE runs the demo collection with the CLI against a mocked server and fails / passes as
  expected (functional test).
- **SC-002**: XPath evaluator rejects XXE payloads (unit test).
- **SC-003**: Worker test: 500 runner executions in one kernel, flat memory, EM cleared after each run.
