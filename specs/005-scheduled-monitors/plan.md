# Implementation Plan: Scheduled monitors

**Branch**: `005-scheduled-monitors` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.17.0** (minor, opt-in)

## Summary

Monitors schedule collection runs (spec 004) of a folder or service, store pass / fail results and hand them to host
handlers (Uptime check, notification, issue) after commit. Unattended runs require an execution-URL allow-list.

## Configuration

```yaml
nowo_api_studio:
    monitors:
        enabled: false
        min_interval: PT5M
        require_execution_allowlist: true
        result_retention_days: 30
        max_concurrent: 2
```

## Data model and migrations

| Table | Columns |
| --- | --- |
| `{prefix}monitor` | `id`, `workspace_id` FK cascade, `service_id` FK nullable cascade, `folder_id` FK nullable cascade, `environment_id` FK nullable SET NULL, `name` varchar(255), `schedule` varchar(64) (cron or ISO-8601 interval), `enabled` bool, `actor_id` varchar(128) nullable **binary NO PAD collation**, `next_run_at` datetime, `running_since` datetime nullable, `last_status` varchar(16) nullable, `created_at`, `updated_at` |
| `{prefix}monitor_result` | `id`, `monitor_id` FK cascade, `run_id` FK nullable SET NULL (spec 004), `status` varchar(16), `passed` int, `failed` int, `duration_ms` int, `summary` json (masked), `created_at` |

Index `idx_monitor_due (enabled, next_run_at)`. Mapped only with `monitors.enabled`. UPGRADING DDL.

Due-claim: `UPDATE monitor SET running_since = :now, next_run_at = :next WHERE id = :id AND next_run_at = :seen AND
running_since IS NULL` (affected rows = 1 → claimed); stale `running_since` older than 2 × timeout is released.

## Architecture

- `Monitor/MonitorScheduler` (stateless): computes `next_run_at` (cron via `dragonmantank/cron-expression` suggested,
  or intervals natively).
- `Monitor/MonitorExecutor`: claims, builds a `RunPlan`, calls `CollectionRunner`, stores the result, commits, then
  dispatches the event and calls handlers (each in try / catch, ids-only logs).
- `Monitor/ApiStudioMonitorResultHandlerInterface` + `MonitorResult` VO (no Doctrine / HTTP types, ARCH-001 rule 3).
- `Command/MonitorsRunDueCommand`, optional `Scheduler/ApiStudioScheduleProvider`.
- Compiler pass check for the allow-list when `require_execution_allowlist`.
- Beacon integration example in USAGE: handler that upserts an UptimeMonitor check and a NotifyKit notification.

## BC analysis

Additive, opt-in, no change to existing classes' public API. Adding a compile-time failure only applies when the host
enables monitors with an empty allow-list (documented). No BC break.

## FrankenPHP worker mode (kernel reset false)

- Scheduler / executor / handlers registry are stateless; claims are DB-level, not in-process.
- EM cleared after each monitor; long-running consumers (Messenger / Scheduler) clear at
  `WorkerMessageHandledEvent`.
- Worker test: 1,000 ticks, two tenants, no leaked results, flat memory.
