# Feature Specification: Sandboxed script runner

**Feature Branch**: `003-sandboxed-script-runner`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.15.0 (minor; the sandbox is a new `scripts.mode` value, `inline` stays the default until 2.0)
**Roadmap item**: 2 of 10 (security)
**Input**: "Sandboxed script runner: worker or sandboxed iframe with its own CSP, no `unsafe-eval` on the page, and/or a
server-side isolated runtime with CPU, memory and time limits. No network except `pm.sendRequest` through the
SSRF-guarded client. Richer `pm` API (chai `expect`, `pm.globals`, `pm.collectionVariables`, `pm.sendRequest`, a closed
allow-list of libraries). Scripts at workspace, folder, service and endpoint level. Secrets never reach the script
context; masking stays intact."

## Context

Up to 1.13 the console runs pre / post scripts with `new Function()` in the page origin (needs `'unsafe-eval'`, reaches
the DOM, the session and same-origin endpoints). 1.13.2 added `scripts.confirm_stored` (the user approves stored code
before it runs) as a stop-gap. This spec removes the root cause: scripts run where they cannot reach the page, the
session or the network.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Browser sandbox (Priority: P1)

Scripts run in a sandboxed, opaque-origin iframe (`sandbox="allow-scripts"`, no `allow-same-origin`) served by the
bundle with its own CSP (`default-src 'none'; script-src 'self' 'unsafe-eval'` limited to that frame document,
`connect-src 'none'`), and talk to the console only through `postMessage` with a structured protocol. The Api Studio
pages no longer need `'unsafe-eval'`.

**Why this priority**: it removes the stored-XSS class (scripts can no longer read the DOM, cookies, CSRF tokens or call
same-origin URLs) for every host, without new infrastructure.

**Independent Test**: with `scripts.mode: sandbox` and a page CSP without `'unsafe-eval'`, a script that calls
`document.cookie`, `fetch('/')`, `parent.document` or `XMLHttpRequest` fails inside the frame; `pm.environment.set`
still updates the console.

**Acceptance Scenarios**:

1. **Given** `scripts.mode: sandbox`, **When** a request with pre / post scripts is sent, **Then** each script runs in
   the frame with a time limit (default 2 s, then the frame is torn down and recreated) and the console applies the
   returned variable / request changes.
2. **Given** a script that tries network access, DOM access of the parent or storage, **Then** it fails with a
   reported error and nothing leaves the frame except the protocol messages.
3. **Given** messages from any other window or with an unexpected shape, **Then** the console ignores them (origin
   `null` + frame `source` check + per-run nonce).
4. **Given** `scripts.mode: sandbox`, **Then** `scripts.confirm_stored` is not needed and defaults to off for that mode.

### User Story 2 - Server-side isolated runtime (Priority: P2)

A host can configure a server-side runner (`scripts.server_runner`) used by the collection runner, the CLI and monitors
(specs 004 / 005), and optionally by the console.

**Why this priority**: unattended runs (CI, monitors) have no browser.

**Independent Test**: with the reference runner configured, `nowo:api-studio:run` executes pre / post scripts with CPU,
memory and wall-time limits; an infinite loop is killed and reported.

**Acceptance Scenarios**:

1. **Given** the port `ApiStudioScriptRunnerInterface` and the reference implementation (a QuickJS / Deno sidecar
   process started per run through `symfony/process`, no file system, no network permission), **Then** limits are
   enforced: time (default 2 s), memory (default 64 MB), output size (default 256 KB).
2. **Given** no server runner (default), **Then** unattended runs skip scripts and report them as "not run" (declarative
   assertions of spec 004 still run in PHP).

### User Story 3 - Richer `pm` API and libraries (Priority: P1)

Scripts get `pm.expect` (chai BDD subset), `pm.test`, `pm.environment`, `pm.variables`, `pm.globals`,
`pm.collectionVariables`, `pm.request` / `pm.response` (headers, json, text, code, responseTime), `pm.sendRequest` and a
closed allow-list of libraries via `require()`: `lodash`, `moment` replacement `dayjs`, `crypto-js`, `ajv`, `chai`,
`uuid`, `xml2js`-like parser. The libraries are bundled into the sandbox frame / runner image; anything else throws.

**Independent Test**: a Postman-style test script using `pm.expect(json.items).to.have.lengthOf(3)` and
`require('lodash')` passes in both runners.

**Acceptance Scenarios**:

1. **Given** `pm.sendRequest(req, cb)`, **Then** the request is executed by the server through `RequestExecutor`
   rules (SSRF guard, execution allow-list, redirect policy, response size limit, history sanitising) via a dedicated
   CSRF-protected endpoint (browser) or the runner callback (server), with a per-run budget (default 10 calls).
2. **Given** `pm.globals` / `pm.collectionVariables`, **Then** globals are per workspace and collection variables per
   service, stored server-side (non-secret only) and changed only when the run is allowed to persist variables.

### User Story 4 - Scripts at every level (Priority: P2)

Pre / post scripts can be set on the workspace, folders (spec 002), service and endpoint; they run outer to inner for
pre-request (workspace → service → folders root→leaf → endpoint) and inner to outer for post-response.

**Acceptance Scenarios**:

1. **Given** scripts on all levels, **Then** the run log labels each one with its level and name.
2. **Given** Postman `event[]` scripts on a collection, folder or request, **When** imported, **Then** they are stored on
   the matching level (only when the instance runs `sandbox` or has a server runner; otherwise reported as not imported).
3. **Given** `workspace.edit_scripts` not granted, **Then** no level's script fields are shown or accepted.

### User Story 5 - Secrets never reach scripts (Priority: P1)

**Acceptance Scenarios**:

1. **Given** a secret variable, **Then** scripts see the `{{secret}}` placeholder / a masked token, never the value, in
   every API (`pm.environment.get`, `pm.request.headers`, `toObject()`).
2. **Given** a response that echoes a secret, **Then** the existing masking (raw, URL, JSON, HTML entity, base64 forms)
   is applied before the response reaches the script.
3. **Given** `pm.sendRequest`, **Then** secret placeholders are resolved server-side only, as for console requests.

### Edge Cases

- A script that never returns: the frame / process is killed at the limit, the request is not sent (pre) or the test
  is failed (post).
- `postMessage` floods: bounded message size (256 KB) and count per run.
- Hosts with a CSP that forbids frames: `frame-src 'self'` is required for the sandbox document; documented.
- Old browsers without `sandbox` support: unsupported (all evergreen browsers support it).

## Requirements *(mandatory)*

- **FR-SCR-001**: `scripts.mode` gains `sandbox`. Values: `inline` (default in 1.x, deprecated in 1.15), `sandbox`,
  `disabled`.
- **FR-SCR-002**: The sandbox document is served by a bundle route with its own CSP header and no cookies used; the
  console page CSP no longer needs `'unsafe-eval'` in `sandbox` mode.
- **FR-SCR-003**: Message protocol: `{v:1, runId, nonce, kind: run|result|sendRequest|log, ...}`; the console validates
  `event.source === frame.contentWindow`, `event.origin === 'null'`, `nonce`.
- **FR-SCR-004**: Limits configurable: `scripts.limits.time_ms` (2000), `memory_mb` (64, server), `send_requests` (10),
  `output_kb` (256).
- **FR-SCR-005**: Port `ApiStudioScriptRunnerInterface::run(ScriptRunRequest): ScriptRunResult` (VOs only, no Doctrine /
  HTTP types); reference implementation `ProcessScriptRunner` (sidecar binary path configured by the host).
- **FR-SCR-006**: `pm.sendRequest` endpoint `POST <ui prefix>/scripts/send-request/{endpointId}` (internal UI JSON,
  CSRF, `workspace.run`), executed through the guarded client; unattended runs call the executor directly.
- **FR-SCR-007**: `pm` API surface listed in US3; library allow-list closed and versioned in the CHANGELOG.
- **FR-SCR-008**: Script columns on workspace (new) and folder (new); service / endpoint columns unchanged.
- **FR-SCR-009**: Secrets masked as described in US5; tests prove no secret value reaches the script context.
- **FR-SCR-010**: `Configuration.php` help and `docs/SECURITY.md` describe the three modes and the residual risk.

## Success Criteria *(mandatory)*

- **SC-001**: With `sandbox`, a hostile-script test corpus (DOM, cookies, storage, fetch, XHR, WebSocket, `top`,
  `opener`, `import()`) is blocked (Playwright).
- **SC-002**: Postman sample collections' test scripts pass unchanged in both runners (chai subset).
- **SC-003**: No secret value appears in any script-visible object (unit + Playwright).
- **SC-004**: Worker test: 1,000 server-runner invocations in one kernel, no leaked process, flat memory.

## Assumptions

- The server runner needs a host-provided sidecar binary (QuickJS or Deno); the bundle ships the wrapper and the
  bundled library file, not the binary.
- `inline` is removed in 2.0; `sandbox` becomes the default there.
