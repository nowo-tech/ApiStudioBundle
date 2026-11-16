# Implementation Plan: Test cases, collection runner and CLI

**Branch**: `004-test-cases-runner-cli` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.16.0** (minor, additive)

## Summary

Declarative assertions evaluated in PHP, a stateless collection runner over folders / services, persisted run reports
with JSON / JUnit / HTML reporters, a newman-like console command, and run pages in the UI. Scripts use the server
runner of spec 003 when configured.

## Configuration

```yaml
nowo_api_studio:
    runner:
        enabled: false          # 1.16: opt-in (maps the assertion / run tables)
        max_iterations: 1000
        max_requests_per_run: 5000
        retention_days: 30
        delay_ms: 0
```

## Data model and migrations

| Table | Columns |
| --- | --- |
| `{prefix}assertion` | `id`, `endpoint_id` FK cascade, `request_example_id` FK nullable cascade, `type` varchar(32), `target` text, `operator` varchar(32), `expected` text nullable, `enabled` bool, `sort_order` int |
| `{prefix}run` | `id`, `workspace_id` FK cascade, `service_id` FK nullable, `folder_id` FK nullable, `environment_id` FK nullable SET NULL, `started_by` varchar(128) nullable **binary NO PAD collation**, `status` varchar(16), `iterations` int, `passed` int, `failed` int, `started_at`, `finished_at` nullable |
| `{prefix}run_result` | `id`, `run_id` FK cascade, `endpoint_id` FK SET NULL, `iteration` int, `response_status` int nullable, `duration_ms` int, `assertions` json (masked excerpts), `script_log` json nullable, `error` varchar(255) nullable |

Mapped only with `runner.enabled` (metadata listener; mapped-superclass trick when off). UPGRADING DDL.

## Architecture

- `Assertion/` evaluators (one class per type) behind `AssertionEvaluatorInterface`, tagged; registry is stateless.
- `Runner/CollectionRunner::run(RunPlan): RunReport` — loads endpoint ids in tree order (spec 002 `FolderTree`),
  executes each via `RequestExecutor` with the same guard / allow-list / redirect / size rules, applies script chain
  through `ApiStudioScriptRunnerInterface` when set, evaluates assertions, writes results in batches (flush + clear per
  batch on the Api Studio EM).
- `Runner/Reporter/{Cli,Json,Junit,Html}Reporter`.
- `Command/RunCommand` (`nowo:api-studio:run`, alias `api-studio:run`), reusing `TenantCommandTrait`.
- `Controller/RunController` (start, progress JSON, cancel, report page, downloads); long runs execute in the request
  with a cap, or via Messenger (`RunCollectionMessage`) when the host has Messenger (optional, detected by service).
- Purge command `nowo:api-studio:runs:purge`.

## BC analysis

All additive and opt-in. No `ApiStudioAction` case added (reuses `workspace.run` / `workspace.edit`). New optional
suggested dependencies (`opis/json-schema`) are only needed for schema assertions; missing dependency → the type is
hidden and saving one is refused with a clear message. No BC break.

## FrankenPHP worker mode (kernel reset false)

- Runner, evaluators and reporters are stateless; the plan and report are VOs passed per call.
- Batched writes clear the Api Studio EM; no managed entity kept across requests or Messenger messages.
- JSON Schema compiled schemas are not cached in properties (or cached in a `ResetInterface` memo cleared per request
  and per Messenger message, with a test).
- Worker test: 500 runs alternating two tenants; no cross-tenant results; flat memory.

## Project Structure

```text
src/Entity/{ApiAssertion,ApiRun,ApiRunResult}.php (+ repositories)
src/Assertion/*.php
src/Runner/{CollectionRunner,RunPlan,RunReport}.php, src/Runner/Reporter/*.php
src/Command/{RunCommand,RunsPurgeCommand}.php
src/Controller/RunController.php, views run/*.html.twig
```
