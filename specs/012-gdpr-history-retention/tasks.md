---
description: "Tasks for 012 GDPR erasure, export and history retention (1.24.0)"
---

# Tasks: GDPR erasure, export and history retention

**Input**: [spec.md](spec.md), [plan.md](plan.md)
**Tests**: required (PHPUnit unit, integration, functional, worker, real databases).

## Phase 1: Ports and configuration

- [x] T001 `Privacy/` ports and value objects (eraser, exporter, retention, results, message)
- [x] T002 `history.retention_days`, `history.purge_batch_size`, `personal_data.enabled` in `Configuration.php`;
  wiring in `ApiStudioExtension::registerPersonalData()`; literal boolean check
- [x] T003 `ApiStudioHistoryScope::actorIdsOf()`; anchored actor id check

## Phase 2: Adapters

- [x] T004 `DoctrineApiStudioPersonalData` (keyset pages, PHP re-check, schema fallback, folder row version)
- [x] T005 `BatchWriter` (runner per batch, retry standalone only, one statement per id)
- [x] T006 `DoctrineApiStudioHistoryRetention`, `HistoryPurgeCommand`, `PurgeApiStudioHistoryHandler`
- [x] T007 `executed_by` binary collation, `idx_history_actor`, `idx_history_executed_at`

## Phase 3: UserKit

- [x] T008 `ApiStudioUserKitEraser` (priority 150) and `ApiStudioUserKitExportProvider` (priority -50)

## Phase 4: Tests

- [x] T009 Unit: value objects, batch writer, handler, bridges, actor ids, DI
- [x] T010 Integration (SQLite): erasure, export, fallback, retention, command
- [x] T011 Functional: standard and DDD kernels, production-built worker kernel with Messenger
- [x] T012 Database: purge vs live writer, byte-exact erasure / export, PHP re-check, lock conflicts standalone and nested

## Phase 5: Docs

- [x] T013 CHANGELOG, UPGRADING, CONFIGURATION, USAGE, SECURITY, CONCURRENCY, FRANKENPHP-WORKER-AUDIT, ARCHITECTURE,
  code inventory
