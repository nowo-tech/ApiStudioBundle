# Concurrency (REQ-RUNTIME-003)

## Table of contents

- [Conflict answers](#conflict-answers)
- [Write paths](#write-paths)
- [Seed API and scope mover (1.17)](#seed-api-and-scope-mover-117)
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
or a transaction-doctrine `ConcurrencyConflict` anywhere in the failure chain — with
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
| Workspace / service / environment / endpoint edit and delete (UI, *pre-1.17*) | One ORM flush (implicit transaction) | Unique slug keys + catch → 422; last writer wins on plain fields | 409 on lock conflicts (1.17) |
| Folder tree moves, folder deletes, imports into a service (*1.14*) | Explicit transaction | Service row `FOR UPDATE`, rules re-checked on rows read under the lock | 409 + `Retry-After` (audit RJ-L1) |
| `pm.globals` / `pm.collectionVariables` saves (*1.15*) | Explicit transaction, DBAL statements | Workspace row lock, caps checked under it | 409 + `Retry-After` (audit RW-D-I1) |
| Environment variable sync, request / response examples, documentation (*pre-1.17*) | One ORM flush | Unique (environment, key) | 409 on lock conflicts (1.17) |
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

- Edit / delete pages of workspaces, services, endpoints and environments (*pre-1.17*) keep last-writer-wins on plain
  fields (no version column): two editors saving the same form at once keep the later save. No duplicates or
  half-saved rows (one flush, unique keys); to be revisited in the concurrency wave of the older paths.
- Hosts that skip the 1.17 column migration (binary tenant columns and variable keys on MySQL / MariaDB, see
  [UPGRADING](UPGRADING.md#to-1170)) keep a case-insensitive unique key: a seed for a tenant id or a variable key that
  differs only in case is refused after its retries instead of creating a second row.
- A nested seed (inside the caller's transaction) reads child rows from the caller's snapshot; the unique keys cover that
  (a row it did not see is refused on insert, not duplicated).
