# Implementation Plan: GDPR erasure, export and history retention

**Branch**: `012-gdpr-history-retention` | **Date**: 2026-11-26 | **Spec**: [spec.md](spec.md)
**Target version**: **1.24.0** (minor). No BC break: new ports, options with safe defaults (the purge runs only when
the host schedules it), new indexes and the `executed_by` collation in a migration.

## Summary

Plain-PHP ports in `Privacy/` (public API), a DBAL adapter on the Api Studio connection, a retention service, a
console command and a Messenger message, and UserKit bridges in `Integration/UserKit/` registered only when UserKit is
installed.

## Technical Context

**Language/Version**: PHP 8.2–8.5
**Primary Dependencies**: Symfony 7.4 / 8, Doctrine DBAL 3 / 4 and ORM 3, transaction-doctrine ^1.10, UserKit ^1.12
(optional), symfony/messenger (optional)
**Storage**: the Api Studio entity manager's connection (default manager in a standard host, dedicated in a DDD host)
**Testing**: PHPUnit (unit, SQLite integration, kernels, worker kernel, real-database suite)
**Constraints**: FrankenPHP worker mode without kernel reset; REQ-RUNTIME-003 (short write units, savepoint-aware
repair, point locks only); LQ exact-key rule

## Constitution Check

- Documented contract: CONFIGURATION, USAGE, UPGRADING, SECURITY, CONCURRENCY, FRANKENPHP-WORKER-AUDIT, ARCHITECTURE. ✅
- Tests first for every port and bridge; real-database races. ✅
- Standard and DDD hosts: ports by actor id, both kernels tested. ✅
- No UI change (no translation, no Modern Web Guidance item). ✅

## Design

| Concern | Decision |
| --- | --- |
| Erasure of history | Delete the rows (not anonymise): bodies and headers are the personal data. |
| Erasure of folders | `created_by = NULL`, `row_version + 1`; the folder is shared team content and stays. |
| Export | Metadata only; URL re-sanitised with `HistorySanitizer::sanitizeUrl()`. |
| Actor ids | `ApiStudioHistoryScope::actorIdsOf()`: host resolver id, or default ids of the original and current identifier. |
| Unmapped columns | Schema lookup of `executed_by` / the folder table when the option that maps them is off. |
| Batches | Keyset pages by id; one runner unit per page; one statement per id (no scan locks). |
| Retention | Ids of the oldest expired rows, then delete by id; refused inside a transaction; 3 attempts per batch. |
| Logs | Counts, cut-off, attempt and exception class; never ids, URLs or names. |
