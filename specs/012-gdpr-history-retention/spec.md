# Feature Specification: GDPR erasure, export and history retention

**Feature Branch**: `012-gdpr-history-retention` (git branch `feat/1.24.0-gdpr-retention`)
**Created**: 2026-11-26
**Status**: Implemented (1.24.0, pending review)
**Target version**: 1.24.0 (minor, no BC break)
**Input**: Beacon map 2026-11-26, worklist item 1 (REQ-DATA parity: every eraser has an export). Request history
stores who ran each request (`executed_by`, Beacon uses `history.scope: user`) and the request / response bodies;
folders store `createdBy`. There was no eraser, no export and no retention.

**Owner decisions (2026-11-26, Q1-3)**: the export holds metadata only (method, URL with query secrets masked, status,
timestamps); request / response bodies are erased, never exported; default history retention 90 days, configurable,
with a purge command, safe from cron and Messenger.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A user is erased (Priority: P1)

The host erases a user through UserKit. Api Studio deletes every history row the user executed, with its bodies,
headers and error message, and keeps the folders they created without a creator.

**Independent Test**: two actors with history rows and folders; erase one; only that actor's rows are gone, its folders
remain with `created_by` null.

**Acceptance Scenarios**:

1. **Given** UserKit installed and `personal_data.enabled` (default), **When** UserKit erases a user whose identifier a
   host eraser already anonymised, **Then** the rows stored under the original identifier's actor id are deleted too.
2. **Given** rows of `u:UserA`, `u:usera` and `u:UserA ` (trailing space), **When** `u:UserA` is erased, **Then** only
   its rows change, on MySQL, MariaDB, PostgreSQL and SQLite, also with an older case-insensitive column.
3. **Given** a lock conflict inside UserKit's transaction, **When** the erasure runs, **Then** the conflict propagates,
   the connection's nesting level is repaired and nothing is half-erased.

### User Story 2 - A user asks for their data (Priority: P1)

**Acceptance Scenarios**:

1. **Given** history rows with secrets in the URL and bodies, **When** the export runs, **Then** it lists endpoint id,
   method, URL (secret query values and userinfo password masked), status and time per row, and the user's folders;
   no body, header, error message or secret appears.

### User Story 3 - Old history goes (Priority: P1)

**Acceptance Scenarios**:

1. **Given** `history.retention_days: 90` (default), **When** the purge command or message runs, **Then** rows executed
   more than 90 days ago are deleted, oldest first, in batches, and later runs delete nothing more.
2. **Given** a live writer holding a recent row, **When** the purge runs, **Then** it never waits for it.
3. **Given** `retention_days: 0`, **Then** nothing is deleted.

### Edge Cases

- `history.scope` switched from `user` to `endpoint` (column no longer mapped) or `folders.enabled` switched off: rows
  stored before are still erased and exported (the column is looked up in the schema).
- Actor ids with spaces or non-ASCII characters (the default id is `u:` + the raw identifier) are erased and exported;
  only invalid UTF-8, NUL and ids over 128 characters are refused (audits ZCL-H1, ZCL-v-L1: `u:ana\n` is accepted);
  the bridges log and skip an id the ports refuse.
- A host resolver id of the wrong shape: logged by class and skipped by the UserKit bridges (no row can hold it).
- The purge message on a bus with `doctrine_transaction`: unrecoverable failure with an error log (audit ZCL-L2).
- The purge inside an open transaction: refused (`LogicException`).

## Requirements *(mandatory)*

- **FR-GDPR-001**: `ApiStudioPersonalDataEraserInterface::erase(actorId)` deletes the actor's history rows and
  anonymises their folders (row version moves); byte-exact; keyset batches; logs counts only.
- **FR-GDPR-002**: `ApiStudioPersonalDataExporterInterface::export(actorId)` returns metadata only (see story 2).
- **FR-GDPR-003**: UserKit eraser and export section `api_studio`, registered together when UserKit is installed and
  `personal_data.enabled` (literal boolean, default true); the eraser covers the original and current identifier.
- **FR-GDPR-004**: `ApiStudioHistoryScope::actorIdsOf(user, originalIdentifier)`.
- **FR-GDPR-005**: `executed_by` compares exactly (binary NO PAD collation on MySQL / MariaDB) and has the index
  `idx_history_actor`; every row read is re-checked with `===`.
- **FR-GDPR-006**: each batch is one write unit through the transaction-doctrine runner; a lock conflict is retried 3
  times standalone and propagates at once inside a caller's transaction; one statement per row by primary key.
- **FR-RET-001**: `history.retention_days` (0–3650, default 90) and `history.purge_batch_size` (10–5000, default 500);
  port `ApiStudioHistoryRetentionInterface`; index `idx_history_executed_at`.
- **FR-RET-002**: `nowo:api-studio:history:purge` (`--dry-run`, `--max-batches`).
- **FR-RET-003**: Messenger message `PurgeApiStudioHistory` and its idempotent `#[AsMessageHandler]`; inside a
  transaction `UnrecoverableMessageHandlingException` (`HistoryPurgeInsideTransactionException`).
- **FR-GDPR-007**: export URL masking covers a user name alone, a password holding `@` and fragment pairs (ZCL-L1).

## Success Criteria

- Unit, integration (SQLite), functional (standard and DDD host kernels, production-built worker kernel with
  Messenger) and real-database tests (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) pass; coverage gate kept.
- No secret, body, actor id or URL in the export beyond the masked URL, nor in any log record.
