# Implementation Plan: Sandboxed script runner

**Branch**: `003-sandboxed-script-runner` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.15.0** (minor)

## Summary

Add `scripts.mode: sandbox` (opaque-origin iframe + `postMessage` protocol + its own CSP), a richer `pm` API with a
closed library allow-list, `pm.sendRequest` through the guarded executor, workspace / folder script levels, and a
server-side runner port with a process-based reference implementation and hard limits.

## Technical Context

**Language/Version**: PHP 8.2–8.5, TypeScript
**Primary Dependencies**: existing; `symfony/process` becomes a `suggest` (required only by `ProcessScriptRunner`)
**Storage**: new nullable script columns; `{prefix}variable_scope` table for globals / collection variables
**Testing**: PHPUnit, Vitest, Playwright (hostile corpus), worker test
**Constraints**: no `'unsafe-eval'` on studio pages in sandbox mode; frame CSP `connect-src 'none'`

## Configuration

```yaml
nowo_api_studio:
    scripts:
        mode: inline              # inline (default, deprecated) | sandbox | disabled
        confirm_stored: true      # inline only (1.13.2); ignored in sandbox mode
        limits: { time_ms: 2000, memory_mb: 64, send_requests: 10, output_kb: 256 }
        server_runner: null       # service id implementing ApiStudioScriptRunnerInterface
        process_runner:           # reference implementation settings (when used)
            binary: null          # absolute path to the qjs / deno binary (host provided)
```

## Data model and migrations

| Table | Change |
| --- | --- |
| `{prefix}workspace` | `pre_request_script`, `post_request_script` text nullable |
| `{prefix}folder` (spec 002) | `pre_request_script`, `post_request_script` text nullable |
| `{prefix}variable_scope` (new) | `id`, `workspace_id` FK cascade, `service_id` FK nullable cascade (null = globals), `variable_key` varchar(255) **binary collation**, `variable_value` text, `updated_at`; unique (`workspace_id`, `service_id`, `variable_key`) |

Additive, nullable; hosts that do not enable `sandbox` or a server runner can skip the migration only if they keep
`scripts.mode` `inline` / `disabled` and folders off: the new columns are mapped conditionally (metadata listener) when
`scripts.mode: sandbox` or `server_runner` is set. UPGRADING gives the DDL.

## Architecture

- `Controller/ScriptSandboxController`: `GET <ui>/scripts/sandbox` returns the static frame document (built asset
  `api-script-sandbox.html` + `api-script-sandbox.js` bundling the `pm` runtime and the allow-listed libraries) with
  headers `Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-eval'; base-uri 'none';
  form-action 'none'; frame-ancestors 'self'`, `Cross-Origin-Resource-Policy: same-origin`, cacheable (immutable).
  `'unsafe-eval'` exists only in this opaque-origin document.
- `api-tester.ts`: runner abstraction `InlineRunner` (current) / `SandboxRunner` (frame per run, nonce, timeout, teardown).
- `Controller/ScriptSendRequestController`: validates the payload as an execute request of the same workspace, runs
  `RequestExecutor` (guard + allow-list + size limit + masking), returns the masked response; budget per run id kept
  in the client and enforced server-side per request (header `X-Api-Studio-Run` + count in the signed run token).
- `Script/ApiStudioScriptRunnerInterface`, `Script/ScriptRunRequest`, `Script/ScriptRunResult` (VOs),
  `Script/ProcessScriptRunner` (one process per run, stdin / stdout JSON lines, `sendRequest` callbacks answered by
  PHP, killed on timeout, `ulimit`-style memory limit via runner flags).
- `Service/ScriptChainBuilder`: ordered scripts from workspace → service → folders → endpoint.
- Secret masking: the console only ever receives placeholders (existing), the sandbox receives the same; server
  runner receives variables after `HostSecretResolver`-free resolution (secrets as placeholders) and masked responses.

## BC analysis

| Change | BC impact | Mitigation |
| --- | --- | --- |
| `scripts.mode: sandbox` | Additive | Default stays `inline`; `inline` deprecated, removed in **2.0** (BC break deferred) |
| Richer `pm` | Additive | Existing scripts keep working (old `expect().to.equal` is a subset of chai) |
| New columns / table | Schema | Mapped only when sandbox / server runner is enabled; migration documented |
| `pm.sendRequest` endpoint | New internal UI route | Under the UI prefix; not a public API (versioning rule does not apply) |
| Postman `event[]` import | Behaviour change only when sandbox / server runner on | Reported in `ImportResult` |

Defaulting to `sandbox` would change host CSP needs (`frame-src 'self'`) and the script semantics (no DOM): **deferred
to 2.0**.

## FrankenPHP worker mode (kernel reset false)

- Runner services are stateless; each run gets its own process / VOs; no process handle survives the call.
- `sendRequest` budgets live in the signed run token, not in service properties.
- The sandbox controller serves a static file; no per-request state.
- Worker test: 1,000 runner calls with a fake binary, no zombie processes, flat memory; two tenants never share
  globals (queries scoped by workspace).

## Project Structure

```text
src/Controller/ScriptSandboxController.php
src/Controller/ScriptSendRequestController.php
src/Script/{ApiStudioScriptRunnerInterface,ScriptRunRequest,ScriptRunResult,ProcessScriptRunner}.php
src/Service/ScriptChainBuilder.php
src/Entity/ApiVariableScope.php + repository
src/Resources/assets/src/api-script-sandbox.ts (+ html), api-tester.ts runners
```
