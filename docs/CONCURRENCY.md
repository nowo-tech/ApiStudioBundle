# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Conflict answers](#conflict-answers)
- [Write paths](#write-paths)
- [Seed API and scope mover (1.17)](#seed-api-and-scope-mover-117)
- [Row versions of the edit forms (1.20)](#row-versions-of-the-edit-forms-120)
- [Tests](#tests)
- [Host options](#host-options)
- [Residual risks](#residual-risks)

How Api Studio stays correct when the same data is written at the same time (two users, two workers, a retried
request). First written for 1.17.0 (workspace scopes and the seed API); paths marked *pre-1.17* were not reworked in
that release and are listed with their current control.

## Conflict answers

Since 1.17 every Api Studio route (`nowo_api_studio_*`, the scoped twins `nowo_api_studio_scoped_*` and
`security.host_routes`) answers a **lock conflict** — a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01), a lock wait
timeout (1205, 55P03), a serialization failure (40001, MariaDB 1020), a transaction-contract `TransientStorageFailure`
or a transaction-doctrine `ConcurrencyConflict` anywhere in the failure (its `getPrevious()` chain and, since the
UA2-5 fix, the exceptions a Messenger wrapper such as a synchronous `HandlerFailedException` carries, through
transaction-doctrine's bounded walk `TransientFailureClassifier::findConflict()`, at most 256 throwables; a
`HandlerFailedException` with a `HandledStamp` means `CONFLICT_002`) — with
(`EventListener\ConcurrencyConflictSubscriber`, through transaction-doctrine's `ConflictCode` and
`ConflictResponsePolicy`):

| Case | Status | Headers | Body (console JSON routes) |
| --- | --- | --- | --- |
| Nothing committed | 409 (or 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` | `{"error":"concurrency_conflict","code":"CONFLICT_001"}` |
| Part committed (`ConcurrencyConflict::$committed`, or the transaction-doctrine request watermark saw a commit) | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` | `{"error":"concurrency_conflict","code":"CONFLICT_002"}` |

Pages get an HTTP exception with that status and headers, rendered by the host's error page. Unique violations,
lost connections and other database errors are **not** mapped (the slug forms answer their own 422). When the host
registers `NowoTransactionDoctrineBundle`, its responder (priority 64) answers first; the bundle's subscriber
(priority 32) is the fallback. Clients key on `code` or `X-Conflict-Code`, never on `error`.

## Write paths

| Path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| `WorkspaceSeeder::seed()` (1.17) | One per attempt, transaction-doctrine `forHostEntityManager()` runner | Workspace row `FOR UPDATE` (lookup before the transaction, no gap lock); unique (tenant, scope, slug) + catch for a new workspace; unique (workspace, slug) / (environment, key) for children; up to 3 attempts from a fresh read (outermost only) | `ConcurrencyConflict` (`CONFLICT_001`) after the last lock conflict; the last unique violation is rethrown |
| `WorkspaceScopeMover::move()` (1.17) | One, transaction-doctrine runner | Row `FOR UPDATE`; unique (tenant, scope, slug) decides a collision | `WorkspaceSlugTakenException` (domain, nothing changed); a lock conflict propagates as `TransientStorageFailure` (one attempt) |
| Workspace create (UI, also on a scoped mount) | Explicit transaction (created event inside, committed event after) | Unique (tenant, scope, slug) + catch → 422 on the slug field | 409 on lock conflicts (1.17) |
| Workspace / service / environment / endpoint edit (UI, *1.20*) | Explicit transaction: claim, then flush | Row version: guarded atomic `UPDATE … row_version = row_version + 1 … AND row_version = ?` (the row lock until the commit); unique slug keys + catch → 422 | Stale version → 409 + `CONFLICT_001` (form again with the stored data); 409 on lock conflicts |
| Workspace / service / environment / endpoint delete (UI, *pre-1.17*) | One ORM flush (implicit transaction) | Type-to-confirm (workspace, service); the delete wins over a concurrent edit (that edit then answers 404) | 409 on lock conflicts (1.17) |
| Folder tree moves, folder deletes, imports into a service (*1.14*) | Explicit transaction | Service row `FOR UPDATE`, rules re-checked on rows read under the lock | 409 + `Retry-After` (audit RJ-L1) |
| `pm.globals` / `pm.collectionVariables` saves (*1.15*) | Explicit transaction, DBAL statements | Workspace row lock, caps checked under it | 409 + `Retry-After` (audit RW-D-I1) |
| Environment variable sync, request / response examples, documentation (*pre-1.17*) | One ORM flush | Unique (environment, key); since 1.20 a variable or documentation write moves the parent's row version (atomic, `RowVersionListener`) | 409 on lock conflicts (1.17) |
| Console execute → history row (*pre-1.17*) | One insert | Append-only | 409 on lock conflicts (1.17) |
| Commands `tenant:move`, `tenant:unsuffix-slugs`, `seed-demo`, `sync-schema` (*pre-1.17*) | Per command | Operator tools, not concurrent by design | Exception |

The bundle never clears or resets an open host entity manager: the runners use `forHostEntityManager()` (replaced
only when a failure closed it), and the seeder refuses to start over pending changes of the manager.

## Seed API and scope mover (1.17)

- **Idempotent by natural keys**: re-running a seed changes nothing but `updated_at`; a replayed call (a retried
  request, a Messenger message handled twice) has exactly one effect.
- **Two writers creating the same workspace**: the unique key (tenant, scope, slug) admits one; the other's insert
  fails, its attempt rolls back, and the next attempt finds the winner's row, locks it and updates it.
- **Two seeds of one workspace**: the second waits for the first's row lock, then reads the first's rows (the lock is
  the transaction's first read, so MySQL / MariaDB REPEATABLE READ takes its snapshot after it) and writes last.
- **A child row inserted concurrently** (a service added from the UI during a seed): the unique key refuses the
  duplicate and the seed runs again from a fresh read.
- **Lock wait / deadlock**: the attempt rolls back (the connection's nesting level is repaired by the runner) and the
  seed runs again after a short full-jitter pause; after 3 attempts a `ConcurrencyConflict` (nothing committed) goes
  to the caller — on an Api Studio route that is a 409 `CONFLICT_001`.
- **Inside a caller's transaction** the seed runs once and propagates: the database may already have rolled back the
  caller's work, so only the caller may retry.
- **Events after the commit** (`ApiWorkspaceSeededEvent`, `ApiWorkspaceScopeChangedEvent`), ids only.
- No HTTP, mail or file I/O under the locks.

## Row versions of the edit forms (1.20)

REQ-RUNTIME-003 rule 4 for the read-modify-write that spans two requests (render the form, save it):

- **Column**: `row_version INTEGER NOT NULL DEFAULT 1` on the workspace, service, endpoint and environment
  (`Entity\RowVersionTrait`). Not a Doctrine `#[ORM\Version]`: the bundle moves it with atomic statements, so writers
  that carry no version (seed API, documentation, folder moves, variables) never fail on it.
- **Edit form**: a hidden, unmapped `rowVersion` field holds the version the form was rendered from (a 422 shows the
  submitted one again). The save (`Controller\RowVersionEditTrait::saveEdit()`) opens a transaction, runs
  `RowVersionGuard::claim()` — `UPDATE <table> SET row_version = row_version + 1 WHERE id = ? AND row_version = ?` — and
  flushes. One row changed: the save owns the row (its lock is held until the commit). No row: someone saved it (or
  deleted it) after the form was rendered: `StaleEditException`, the transaction is rolled back (only the level the
  save opened), nothing is written.
- **Two editors on the same version**: the second one's `UPDATE` waits for the first one's row lock, then matches no
  row (MySQL / MariaDB read the latest committed row for an `UPDATE`; PostgreSQL READ COMMITTED re-checks the condition
  on the new row version; under REPEATABLE READ / SERIALIZABLE PostgreSQL raises a serialization failure, which is a
  409 `CONFLICT_001` too). Exactly one saves.
- **Stale answer** (HTML): the page re-reads the rows the refused form changed (the main row, its translations or
  variables, the other environments of the workspace; orphan removals of the form are cancelled), shows the form again
  with the stored data and the error flash `conflict.stale_edit`, with the status and headers of
  `ConcurrencyConflictSubscriber::answerFor()`: 409 (or `conflict_status`) + `X-Conflict-Code: CONFLICT_001` +
  `Retry-After`, or `CONFLICT_002` without `Retry-After` when the request's commit watermark saw earlier work committed.
  A row deleted meanwhile answers 404. Anywhere else the exception escapes, it is a transaction-doctrine
  `LockConflictExceptionInterface` and the conflict subscriber answers it.
- **Other writers** (`Doctrine\RowVersionListener`, `postUpdate` / `postPersist` / `postRemove`, every manager): an ORM
  update of a versioned row that does not set `rowVersion` itself, and any write of an endpoint translation or an
  environment variable, runs `UPDATE … SET row_version = row_version + 1 WHERE id = ?` in the flush's transaction and
  records the stored value on the entity. A flush that writes a row and its children moves it once per written row;
  only growth matters. New rows (inserted at 1), claimed rows and rows being deleted are skipped.
- **Worker mode**: the guard is static and the listener stateless; the version lives on the entity (one row per
  request) and in the database, never on a service.
- **Template overrides** that drop the hidden field: the save uses the version this request loaded (last writer wins
  only between two page loads). A malformed value never matches (409).

## Tests

- `tests/Database/WorkspaceSeedRaceTest.php` (testsuite `Database`): MySQL 8.4, MariaDB 11.4, PostgreSQL 16, a second
  session in its own process (`tests/Database/Support/second-connection.php`):
  - two writers racing for the same new workspace (unique key) — one row, the loser updates it;
  - two seeds of one workspace (row lock) — no lost update, no duplicate;
  - a child row inserted by another session — no duplicate service;
  - a lock held past the lock wait timeout — `ConcurrencyConflict` after 3 attempts, its 409 + `CONFLICT_001` +
    `Retry-After` answer, nothing half-saved, the connection usable for the next seed;
  - scopes compared byte-exact on the server (`project:a`, `project:A`, `project:a.` are three scopes);
  - a scope move into a taken slug — nothing changed, connection usable;
  - a look-alike tenant (`ACME` vs `acme`) sees nothing and does not collide; variable keys that differ in case are two
    variables;
  - a seed after a concurrent move, and the mover with a stale copy in the manager, read the locked row.
- `tests/Integration/Seed` (SQLite): idempotent re-run, update, additive, retry of a unique violation and of transient
  failures, a conflict from below passed on, nested transaction (one attempt), pending changes refused.
- `tests/Unit/EventListener/ConcurrencyConflictSubscriberTest.php`: 409 / 503, `CONFLICT_001` / `CONFLICT_002`
  (committed flag and request watermark), JSON routes, unmapped failures.
- `tests/Database/RowVersionRaceTest.php` (testsuite `Database`, the same three servers and second session): two
  editors on the same version (the second waits for the lock, then writes nothing); the first editor holding the lock
  while the second's guarded `UPDATE` waits (no lost update); a lock wait timeout on the claim (409 + `CONFLICT_001` +
  `Retry-After`, nothing written, connection usable); a writer without a version after a concurrent bump (atomic: 3,
  never a second 2; a form at the old version is refused); translation and variable writes moving their parent.
- `tests/Functional/StaleEditTest.php` (SQLite kernel): each of the four edit pages saved after another save (409,
  `X-Conflict-Code`, `Retry-After`, the message, the stored data unchanged, the new form saves); two tabs; malformed and
  missing versions; a 422 keeping the submitted version; a refused environment save changing no default flag; a
  documentation save refusing an older endpoint form; a row deleted meanwhile (404).
- `tests/Integration/Doctrine/RowVersionTest.php` (SQLite): claim, stale claim, bump of a deleted row, child writes,
  ignored entities, the stale conflict answer (`CONFLICT_001`, and `CONFLICT_002` when work was committed).
- CI job **Databases** (DBAL 3 and DBAL 4) runs the race suite with `--fail-on-skipped`; locally `make test-with-db`.

## Host options

```yaml
nowo_api_studio:
    concurrency:
        conflict_status: 409   # or 503
        retry_after: 1         # seconds, CONFLICT_001 only
```

With transaction-doctrine's bundle registered and `commit_detection` on, the request commit watermark lets the
answer say `CONFLICT_002` when the host or another bundle committed earlier in the same request.

## Residual risks

- (Closed in 1.20: the edit pages of workspaces, services, endpoints and environments are no longer last-writer-wins.)
- The inline documentation editor of an endpoint (`…/documentation`, JSON) carries no version: it overwrites the title
  and description of one locale (last writer wins between two documentation saves), but it moves the endpoint's
  version, so an older endpoint edit form is refused instead of reverting it.
- Host SQL writes (DBAL, raw SQL) to the four tables that do not add `row_version = row_version + 1` are not seen by
  older forms.
- Hosts that skip the 1.17 column migration (binary tenant columns and variable keys on MySQL / MariaDB, see
  [UPGRADING](UPGRADING.md#to-1170)) keep a case-insensitive unique key: a seed for a tenant id or a variable key that
  differs only in case is refused after its retries instead of creating a second row.
- A nested seed (inside the caller's transaction) reads child rows from the caller's snapshot; the unique keys cover that
  (a row it did not see is refused on insert, not duplicated).
