# Feature Specification: Scheduled monitors with a host extension point

**Feature Branch**: `005-scheduled-monitors`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.17.0 (minor, opt-in)
**Roadmap item**: 4 of 10
**Depends on**: 004 (collection runner), 003 (server runner, optional)
**Input**: "Scheduled monitors with a host extension point. A pass/fail result that the host can turn into an Uptime
check, a notification, or an issue on failure. Hosts can require an execution-URL allow-list for unattended runs."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Schedule a monitor (Priority: P1)

An editor schedules a folder or service run (environment, cron expression or interval ≥ `monitors.min_interval`,
enabled flag). Due monitors run unattended and keep a result history.

**Independent Test**: monitor every 5 minutes; `nowo:api-studio:monitors:run-due` at T and T+5 runs it twice; a
mocked failure records a failed result.

**Acceptance Scenarios**:

1. **Given** a due monitor, **When** the scheduler tick runs (`nowo:api-studio:monitors:run-due` from cron, or the
   Symfony Scheduler provider when the host uses it), **Then** the monitor runs once (row lock / `next_run_at`
   compare-and-set so two workers never run it twice).
2. **Given** a run, **Then** the result (pass / fail, counts, duration, failing assertions masked) is stored and the
   monitor's `last_status` updated.
3. **Given** `monitors.enabled: false` (default), **Then** nothing is mapped, no routes, no command effect.

### User Story 2 - Host extension point (Priority: P1)

The host turns results into Uptime checks, notifications or issues.

**Acceptance Scenarios**:

1. **Given** services tagged `nowo_api_studio.monitor_result_handler` implementing
   `ApiStudioMonitorResultHandlerInterface::handle(MonitorResult $result): void`, **Then** each is called after the
   result is committed, with a VO (monitor id, workspace id, tenant, status, previous status, counts, failing
   assertion summaries without secrets, run id, report URL path).
2. **Given** a handler that throws, **Then** the result is kept, the error is logged (ids only) and other handlers
   still run.
3. **Given** `ApiMonitorStatusChangedEvent` (pass → fail, fail → pass), **Then** hosts that only need transitions can
   listen to the event instead (dispatched after commit).

### User Story 3 - Unattended runs need an allow-list (Priority: P1)

**Acceptance Scenarios**:

1. **Given** `monitors.require_execution_allowlist: true` (default `true`), **When** `execution_url_allowlist` is
   empty, **Then** container compilation fails while monitors are enabled (as `execution_url_allowlist_required`).
2. **Given** a monitor whose target URLs are outside the allow-list, **Then** the run fails closed (status `error`,
   no request sent).
3. **Given** secrets, **Then** unattended runs resolve stored secrets only (host secrets need an actor; the monitor's
   configured actor id is passed to the host secret source, which may refuse).

### Edge Cases

- Missed ticks (downtime): the monitor runs once on the next tick, not once per missed slot.
- Overlapping run still in progress: skipped with status `skipped_overlap`.
- Monitor of a deleted folder / service: disabled automatically, handlers receive `status: error` once.

## Requirements *(mandatory)*

- **FR-MON-001**: Config `monitors.enabled` (false), `min_interval` (`PT5M`), `require_execution_allowlist` (true),
  `result_retention_days` (30), `max_concurrent` (2).
- **FR-MON-002**: Entities `ApiMonitor`, `ApiMonitorResult`.
- **FR-MON-003**: Command `nowo:api-studio:monitors:run-due` (idempotent, lock per monitor), optional Scheduler
  `ScheduleProvider` when `symfony/scheduler` is installed.
- **FR-MON-004**: SPI `ApiStudioMonitorResultHandlerInterface` + event `ApiMonitorStatusChangedEvent` after commit.
- **FR-MON-005**: Monitor CRUD UI (UiKit list / form / show with result history and sparkline-free status list).
- **FR-MON-006**: Every outbound request goes through `RequestExecutor` (SSRF guard + allow-list).

## Success Criteria *(mandatory)*

- **SC-001**: Two concurrent `run-due` processes never run the same monitor slot twice (functional test with two EMs).
- **SC-002**: A handler receives no secret value (test with a secret echoed by the mocked target).
- **SC-003**: Worker test: 1,000 ticks in one kernel, flat memory, no cross-tenant results.
