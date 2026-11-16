---
description: "Tasks for 005 scheduled monitors (1.17.0)"
---

# Tasks: Scheduled monitors

- [ ] T001 `monitors.*` configuration, allow-list compile check
- [ ] T002 Entities `ApiMonitor`, `ApiMonitorResult` + conditional mapping (NO PAD `actor_id`)
- [ ] T003 `MonitorScheduler` (cron / interval, missed ticks collapse)
- [ ] T004 `MonitorExecutor` (claim CAS, run, store, commit, event, handlers)
- [ ] T005 SPI `ApiStudioMonitorResultHandlerInterface`, `MonitorResult` VO, `ApiMonitorStatusChangedEvent`
- [ ] T006 `nowo:api-studio:monitors:run-due` + optional Scheduler provider
- [ ] T007 Monitor UI (list / form / show), route actions (`workspace.edit` to manage, `workspace.view` to read)
- [ ] T008 [P] Tests: concurrent claim with two EMs, overlap skip, handler exception isolation, no secrets in VO
- [ ] T009 [P] Worker test (1,000 ticks), retention purge
- [ ] T010 Docs (USAGE with a Beacon / Uptime handler example), UPGRADING DDL, CHANGELOG, translations, inventory
