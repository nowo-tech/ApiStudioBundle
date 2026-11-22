# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.19.1] - 2026-11-22](#1191---2026-11-22)
- [[1.19.0] - 2026-11-22](#1190---2026-11-22)
- [[1.18.0] - 2026-11-19](#1180---2026-11-19)
- [[1.17.1] - 2026-11-19](#1171---2026-11-19)
- [[1.17.0] - 2026-11-19](#1170---2026-11-19)
- [[1.16.0] - 2026-11-18](#1160---2026-11-18)
- [[1.15.0] - 2026-11-17](#1150---2026-11-17)
- [[1.14.0] - 2026-11-16](#1140---2026-11-16)
- [[1.13.2] - 2026-11-16](#1132---2026-11-16)
- [[1.13.1] - 2026-11-15](#1131---2026-11-15)
- [[1.13.0] - 2026-11-15](#1130---2026-11-15)
- [[1.12.4] - 2026-11-15](#1124---2026-11-15)
- [[1.12.3] - 2026-11-13](#1123---2026-11-13)
- [[1.12.2] - 2026-11-12](#1122---2026-11-12)
- [[1.12.1] - 2026-11-12](#1121---2026-11-12)
- [[1.12.0] - 2026-11-11](#1120---2026-11-11)
- [[1.11.0] - 2026-11-04](#1110---2026-11-04)
- [[1.10.1] - 2026-11-04](#1101---2026-11-04)
- [[1.10.0] - 2026-10-30](#1100---2026-10-30)
- [[1.9.2] - 2026-10-29](#192---2026-10-29)
- [[1.9.1] - 2026-10-28](#191---2026-10-28)
- [[1.9.0] - 2026-10-28](#190---2026-10-28)
- [[1.8.1] - 2026-10-28](#181---2026-10-28)
- [[1.8.0] - 2026-10-28](#180---2026-10-28)
- [[1.7.0] - 2026-10-26](#170---2026-10-26)
- [[1.6.1] - 2026-10-24](#161---2026-10-24)
- [[1.6.0] - 2026-10-21](#160---2026-10-21)
- [[1.5.2] - 2026-10-21](#152---2026-10-21)
- [[1.5.1] - 2026-10-20](#151---2026-10-20)
- [[1.5.0] - 2026-10-19](#150---2026-10-19)
- [[1.4.1] - 2026-10-18](#141---2026-10-18)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.1] - 2026-10-13](#131---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-13](#120---2026-10-13)
- [[1.1.0] - 2026-10-13](#110---2026-10-13)
- [[1.0.23] - 2026-10-13](#1023---2026-10-13)
- [[1.0.22] - 2026-10-09](#1022---2026-10-09)
- [[1.0.21] - 2026-09-27](#1021---2026-09-27)
- [[1.0.20] - 2026-08-24](#1020---2026-08-24)
- [[1.0.19] - 2026-08-20](#1019---2026-08-20)
- [[1.0.18] - 2026-08-20](#1018---2026-08-20)
- [[1.0.17] - 2026-08-19](#1017---2026-08-19)
- [[1.0.16] - 2026-08-18](#1016---2026-08-18)
- [[1.0.15] - 2026-08-04](#1015---2026-08-04)
- [[1.0.14] - 2026-08-04](#1014---2026-08-04)
- [[1.0.13] - 2026-07-30](#1013---2026-07-30)
- [[1.0.12] - 2026-07-29](#1012---2026-07-29)
- [[1.0.11] - 2026-07-29](#1011---2026-07-29)
- [[1.0.10] - 2026-07-28](#1010---2026-07-28)
- [[1.0.9] - 2026-07-28](#109---2026-07-28)
- [[1.0.8] - 2026-07-28](#108---2026-07-28)
- [[1.0.7] - 2026-07-27](#107---2026-07-27)
- [[1.0.6] - 2026-07-27](#106---2026-07-27)
- [[1.0.5] - 2026-07-27](#105---2026-07-27)
- [[1.0.4] - 2026-07-27](#104---2026-07-27)
- [[1.0.3] - 2026-07-23](#103---2026-07-23)
- [[1.0.2] - 2026-07-22](#102---2026-07-22)
- [[1.0.1] - 2026-07-16](#101---2026-07-16)
- [[1.0.0] - 2026-07-16](#100---2026-07-16)

## [Unreleased]

### Added

- **Row versions on the edit forms** (REQ-RUNTIME-003 rule 4, closes the *pre-1.17* last-writer-wins residual): the
  workspace, service, endpoint and environment get a `row_version` column (`INTEGER NOT NULL DEFAULT 1`, additive:
  generate the migration, see [UPGRADING](UPGRADING.md#to-1200)). Their edit forms carry it in a hidden `rowVersion`
  field and save only on that version: the save claims the row with a guarded atomic
  `UPDATE … SET row_version = row_version + 1 WHERE id = ? AND row_version = ?` and flushes in the same transaction.
  A form saved after another save (or after the seed API, a documentation save, a folder move or a variable change of
  that row) answers **409** (or the configured `concurrency.conflict_status`) with `X-Conflict-Code: CONFLICT_001` +
  `Retry-After` (`CONFLICT_002` without `Retry-After` when the request committed earlier work), shows the stored data
  with the message `conflict.stale_edit` (every shipped locale), and writes nothing. Two editors racing on the same
  version: exactly one saves. A row deleted meanwhile answers 404.
- `Doctrine\RowVersionListener` (Doctrine listener, all managers): every other ORM write of those rows, and of endpoint
  translations and environment variables, moves the parent's version atomically, so an older form is refused instead
  of overwriting that change.
- `Doctrine\RowVersionGuard`, `Concurrency\StaleEditException` (a transaction-doctrine lock conflict: answered 409 by
  the conflict subscriber anywhere it escapes), `Entity\RowVersionedInterface`, `Form\RowVersionField` (option
  `row_version` on `ApiWorkspaceFormType`, `ApiServiceFormType`, `ApiEndpointFormType`, `ApiEnvironmentFormType`), and
  `ConcurrencyConflictSubscriber::answerFor()` (the status and headers for a page that answers a conflict itself).
- Real-database race tests (`tests/Database/RowVersionRaceTest.php`, MySQL 8.4, MariaDB 11.4, PostgreSQL 16): two
  editors on the same version, the first editor holding the lock, a lock wait timeout on the claim (409 +
  `CONFLICT_001`, connection usable), writers without a version moving it atomically, child rows moving their parent.

## [1.19.1] - 2026-11-22

### Fixed

- Translations: `variables.syntax` and `field.variable_key` ("Placeholder") are now translated in de, es, fr, it, nl and pt; `tester.tab.pre_script` / `tester.tab.post_script` ("Pre-request" / "Post-request") in de, es, fr, it, nl and pt; `field.pre_request_script` / `field.post_request_script` in es and pt; and es `tester.tab.params`, `tester.tab.docs`, `tester.tab.headers`, `field.headers`, `field.query_params` and `field.default_headers` use Spanish wording. A new strict `TranslationCatalogueTest` fails on a non-en locale missing a key, on a non-en value identical to English (any length, placeholders included; values without letters are skipped) unless allow-listed per locale (brands, units, protocols, loanwords, true cognates), on a non-es value identical to the Spanish one (longer than 12 characters, different from English) unless allow-listed as genuinely shared, and on stale entries in either allow-list.

[1.19.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.19.1

## [1.19.0] - 2026-11-22

### Added

- **Workspace query port** (DDD-host checklist B1/B7): `Query\ApiWorkspaceQueryInterface` (autowirable; Doctrine
  implementation on the bundle's own manager) lists and counts workspaces without DQL over the bundle's tables and
  without loading an entity. `find(ApiWorkspaceCriteria)` returns an `ApiWorkspacePage` of `ApiWorkspaceView` scalars
  (id, name, slug, description, enabled, tenant, scope, created / updated); `count()`, `get(tenant, id)`,
  `workspaceIdOfService(tenant, id)` and `workspaceIdOfEndpoint(tenant, id)`. Criteria: tenant (mandatory), scope (any,
  tenant level, or one), an id set (≤ 10 000), a literal case-insensitive text on name or slug, enabled, sort (name,
  created, updated, id; ascending or descending, id as tie-break) and paging (limit clamped to 1–100). Tenant and scope
  compare exactly (bound parameters, then `===` in PHP). A store read, not an access check: narrow it with your own
  permission port (`withIds()`).
- **Access by ids** (B1/B8): `Security\ApiWorkspaceAccessInterface::isGranted(user, action, tenant, workspaceId)` and
  `viewableWorkspace(user, tenant, workspaceId)`. The user is your security user object, an opaque actor id string or
  null. The workspace is read by exact id and tenant, then the studio's checks run: `visibleWorkspaceIds()` (user
  objects), the scope checker for a scoped workspace (deny by default), and the permission checker for `workspace.view`
  then the action. Unknown ids, other or look-alike tenants, unusable actor ids, refusals and failures (logged with the
  exception class only) all give the same `false` / `null`. Nothing is kept between calls (worker mode). It requires
  `security.permission_checker`: without one (default checker, everything granted) every call is refused, without
  reading the store, and a warning is logged (fail closed). `find()` pages can be shorter than the limit on a folding
  collation (limit in SQL, exact re-check in PHP); the total is exact.
- `ApiStudioSubject::forWorkspaceId(tenant, id, scope, actorId)` and `ApiStudioSubject::forTenant()`; the subject and
  `ScopeAccessSubject` carry `actorId` (the opaque id history rows and events record), set by the studio's own checks
  too, so a host checker can decide on ids alone. `ApiStudioAuthorization::actorId()`.
- Every domain event carries `occurredAt` (`DateTimeImmutable`, a trailing optional constructor argument):
  `ApiWorkspaceCreatedEvent`, `ApiWorkspaceCreatedCommittedEvent`, `ApiWorkspaceDeletedEvent`, `ApiWorkspaceMovedEvent`,
  `ApiWorkspaceSeededEvent`, `ApiWorkspaceScopeChangedEvent`, `ApiStudioResourceChangedEvent`,
  `ApiRequestExecutedEvent` (B4).

### Deprecated

- `ApiStudioSubject::$workspace` (use `$workspaceId`; null in the subjects of `ApiWorkspaceAccessInterface`), and
  `ApiWorkspaceCreatedEvent::$workspace` / `$user` (use `$workspaceId`, `$slug`, `$actorId`; prefer
  `ApiWorkspaceCreatedCommittedEvent`, which fires after the commit). Kept in 1.x; no removal is planned.

### Changed

- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60; shared top bar, aside, page header and section cards; no demo stylesheet or `<style>`). Demo only, no bundle change.
- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).
- `ApiStudioAuthorization::actorId()` answers null (and logs the exception class) when the host's
  `history.actor_resolver` throws anything, not only `UnexpectedValueException`; a new optional trailing `$logger`
  constructor argument (autowired).
- The studio's permission checks resolve the actor id (`history.actor_resolver`, else `u:` + the user identifier) for
  `ApiStudioSubject::$actorId`: a host resolver is now called on permission checks too. A resolver that answers another
  shape leaves `actorId` null there (an id-only checker then denies; a checker that reads the user object is
  unaffected). Tests: a DDD host kernel with a private manager and id-only checkers (`tests/Functional/IdOnlyHost`), and
  the exact-key scenario of the query port on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (`tests/Database`) (B10).

[1.19.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.19.0

## [1.18.0] - 2026-11-19

### Added

- **SearchKit groups** (opt-in `search_kit.enabled`, `search_kit.groups.*`; needs `nowo-tech/search-kit-bundle` `^1.9`, suggested): workspaces and services by name, endpoints by name, path and HTTP method in the header search, in org (tenant) and project (workspace scope) scope. Each group (`Bridge\SearchKit\ApiStudioSearchDefinition`) runs the studio's entry chain (signed-in viewer, roles, access checker, `studio.open` with the scope checker in a scope, feature gate), filters in SQL (tenant, scope, visible ids), re-checks every row's tenant and scope byte-exact, then checks `workspace.view` per workspace and the folder checker per endpoint in one batch (two statements per search, three with folder rows). Any refusal returns null (no count, no hint); totals count visible rows only. Results link to the tenant-level show pages (also from a project-scoped search with `scope.tenant_list: all`), so recents revalidate. Results carry names, the protocol, the method and the path without its query string (the path is matched in full): never a base URL, header, auth value, body, script or variable. New host hook `ApiStudioSearchContextEvent` (deny, exclude a group, map the search scope, tenant, link parameters); per-row rules go in SearchKit's `ITEM_VISIBILITY` event. See USAGE "Global search".
- `ApiStudioAccessSubscriber::admitsSearch()`: the studio entry chain as a boolean for requests that are not studio routes.

### Changed

- Authorization memos (`ApiStudioAuthorization::scope()`, decisions) are also keyed by the tenant, so a tenant switched with `ApiStudioTenantContext::runAs()` inside a request is never served another tenant's memo.
- CI installs SearchKit (optional, PHP >= 8.3) with `.github/ci/search-kit.sh` on PHP 8.3+ only; it is not in `composer.json` or the lock, so PHP 8.2 keeps installing from the lock and the SearchKit tests are skipped there.

[1.18.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.18.0

## [1.17.1] - 2026-11-19

### Fixed

- **Lock conflicts inside Messenger wrappers answer 409** (audit UA2-5, REQ-RUNTIME-003 rule 6): `ConcurrencyConflictSubscriber` walked only `getPrevious()`, so a deadlock in the second wrapped exception of a synchronous Messenger `HandlerFailedException` answered 500. Detection now uses transaction-doctrine's bounded walk (`TransientFailureClassifier::findConflict()`: `getPrevious()` plus `getWrappedExceptions()`, each throwable once, at most 256). `CONFLICT_001` vs `CONFLICT_002` still comes from `ConflictCode::forRequest()` (a `HandlerFailedException` with a `HandledStamp` answers `CONFLICT_002`); unique violations and lost connections are still not conflicts; host routes are never answered. A lost savepoint of unknown outcome now also answers `CONFLICT_002` instead of 500. Requires `nowo-tech/transaction-doctrine` `^1.7.1`.

[1.17.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.17.1

## [1.17.0] - 2026-11-19

### Added

- **Workspace scopes** (`scope.*`, off by default; SYMFONY-BEACON spec 243): an optional opaque host scope on each workspace one level below the tenant (`WorkspaceScope`, e.g. `project:<uuid>`, `^[A-Za-z0-9._:-]{1,128}$`, compared byte-exact), mapped as `host_scope` (binary NO PAD collation on MySQL / MariaDB) with the slug unique per (tenant,) scope. A host `ScopeResolverInterface` gives the scope of a request from its route (`ScopeRouteContext`: route name and scalar parameters, no HTTP type) and a mandatory `ScopeAccessCheckerInterface` decides every action per scope on top of the permission checker (deny by default: `studio.open` / `workspace.view` denied → 404, other actions → 403 or the 1.16 404 policy) and which scopes tenant-level lists show (`visibleScopes()`). Every workspace lookup (`ApiStudioScope::contains()`), list, count, the sidebar and the tenancy SQL filter are narrowed by the scope, so a workspace of another scope answers 404 under a scope's prefix (no IDOR). New workspaces get the request's scope; `ApiWorkspace::assignScope()` / `getScope()`.
- **Scoped mount** (`scope.path`, `scope.path_requirements`): the same `routes.yaml` import also loads a twin of every Api Studio route under the host prefix (`nowo_api_studio_scoped_*`, e.g. `/organizations/{organization}/projects/{project}/api-studio/...`), with the same action map, JSON routes, 404 policy and headers; every bundle link stays in the mount (`ApiStudioUrlGenerator`, Twig `api_studio_path()`), and the mount's placeholders are router-context defaults for the request. Scoped pages extend `scope.layout_template` (the host's project layout) and get `nowo_api_studio_scope` (also Twig `nowo_api_studio_scope()`).
- **Tenant-level list with scope badges** (`scope.tenant_list: all|unscoped`, `scope.label_provider` → `ScopeLabelProviderInterface`, one call per page).
- **Seed API** `WorkspaceSeeder::seed(WorkspaceSeed): WorkspaceSeedResult`: creates or updates a workspace with services, endpoints, environments, plain variables and secret **references** by name (`SecretReferenceSeed` → `{{secret:<source>/<id>}}`, needs `secrets.source`), idempotent by natural keys (workspace by tenant + scope + slug), additive, never accepting or logging secret values. `ApiWorkspaceSeededEvent` after the commit.
- **`WorkspaceScopeMover::move()`**: the host changes a workspace's scope (row lock, `WorkspaceSlugTakenException` on a slug collision, `ApiWorkspaceScopeChangedEvent` after the commit).
- `scope` on `ApiWorkspaceCreatedEvent`, `ApiWorkspaceCreatedCommittedEvent`, `ApiWorkspaceDeletedEvent`; `scope` on `ApiStudioSubject`.
- **Lock conflicts answer 409** (REQ-RUNTIME-003, `concurrency.conflict_status: 409|503`, `concurrency.retry_after`): a deadlock, lock wait timeout or serialization failure on an Api Studio route answers `X-Conflict-Code: CONFLICT_001` + `Retry-After`, or `CONFLICT_002` without it when part of the request was committed (transaction-doctrine `ConflictCode::forRequest()`); console JSON routes get `{"error":"concurrency_conflict","code":…}`. Other database errors are not mapped.
- `docs/CONCURRENCY.md` (write paths, conflict codes, host options), a real-database CI job "Databases" (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; DBAL 3 and 4) with two-connection race tests, `make test-with-db`.

### Fixed

- **Look-alike tenants** (audit UA-L1, pre-existing since 1.11, Low): on MySQL / MariaDB the tenant columns compared case-insensitively, so the workspace list, dashboard counts and sidebar of tenant `ACME` could show workspaces of tenant `acme` (opening them was already refused), and their slugs collided on the unique key. `tenant_type` / `tenant_id` now get a binary NO PAD collation (migration), lists and the sidebar re-check the tenant byte-exact in PHP, and `ApiStudioTenant` refuses a trailing newline in the type or id.
- **Environment variable keys compare exactly** (audit UA-I3): `variable_key` gets a binary NO PAD collation on MySQL / MariaDB (`{{API_KEY}}` and `{{api_key}}` were already two variables for the console; the unique key now agrees).
- Workspace slug validation is per (tenant, scope) with scopes on (audit UA-M1): no false "slug taken" at tenant level for a slug used only in a project, no oracle of another scope's slugs without the SQL filter.
- A resolver that refuses a route value (`InvalidArgumentException`, e.g. an invalid `WorkspaceScope`) answers 404, not 500 (audit UA-L3).
- The seeder and the scope mover fill the workspace from the locking read itself (`refresh(…, PESSIMISTIC_WRITE)`), so a move committed meanwhile is never overwritten: neither when the manager already held a copy, nor when they run nested in a caller's transaction whose MySQL / MariaDB REPEATABLE READ snapshot predates the move (a plain refresh after the lock read that snapshot; audit UA2-1).
- The tenant commands re-check the tenant exactly in PHP (audit UA2-2): before the collation migration, `nowo:api-studio:tenant:unsuffix-slugs --tenant-id=acme` also renamed the slugs of tenant `ACME`, `nowo:api-studio:secrets:status --tenant` counted its secrets and `nowo:api-studio:tenant:move` counted its slug as taken at the destination.
- The seeder and the scope mover take the workspace row lock with the first read (`find(…, PESSIMISTIC_WRITE)`, audit UA3-1), so no consistent read of an enclosing REPEATABLE READ transaction happens before the lock.
- `nowo:api-studio:tenant:move` answers the friendly "rename one of them first" error (exit 1) when the database's own unique key refuses the UPDATE for a look-alike tenant (audit UA3-2), instead of a raw exception.
- `SlugFormat` pattern uses the `/D` modifier (audit UA3-3): a slug with a trailing newline is no longer accepted.

### Changed

- Requires `nowo-tech/transaction-doctrine ^1.6` and `nowo-tech/transaction-contract ^1.0` (hard dependencies, also with scopes off; registering `NowoTransactionDoctrineBundle` stays optional).
- `ConcurrencyConflictSubscriber` is always on, also with `scope.enabled: false`: a lock conflict on any Api Studio route now answers 409 (or `concurrency.conflict_status: 503`) with `X-Conflict-Code` instead of a 500 (audit UA-I2).
- `ApiStudioSecretRef` carries the workspace's `scope` (appended `?string $scope = null`): host secret sources must authorise `source/id` against the tenant **and** the scope (audit UA-M2).
- The tenancy SQL filter (`tenancy.sql_filter`) also narrows by scope and runs with scopes on even without tenancy.
- Bundle templates link with `api_studio_path()` (same URLs without a scoped mount).

[1.17.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.17.0

## [1.16.0] - 2026-11-18

### Added

- **Plan / feature gate** (`security.feature_gate`): a host service implementing `ApiStudioFeatureGateInterface::isEnabled(?object $user, ApiStudioTenant $tenant, Request $request): bool` is consulted on every Api Studio route, after the role check, the access checker and `studio.open` (someone who may not open the studio never learns its plan) and before the controller. `false` refuses with the configured answer; deny by default (only `true` passes, an exception fails the request). Null (default) = no gate.
- **403 / 404 policy** (`security.access_denied_response: 403|404`, default `403` = unchanged): with `404`, every refusal on an Api Studio route — permission checker, tenancy, feature gate, role / access checks of a signed-in user, the host's own denials on its `host_routes` — answers exactly the genuine not-found of that route (same body, `Cache-Control: no-store, private`, no `Expires`), so a refusal is no oracle. Refusals thrown as exceptions become one `NotFoundHttpException('Not Found')` before the firewall and the error renderer (the original is logged at info level); the console's JSON answers (`/execute`, `pm.sendRequest`, script variables) answer their own `{"error":"Endpoint not found."}` 404. Users who are not fully authenticated — anonymous, remember-me, or a two-factor login in progress (scheb/2fa `TwoFactorTokenInterface`; the firewall's trust resolver decides) — keep the firewall's answer when the role / access check refuses them (login or second-factor redirect, identical for every URL). Literal value (no `%env()%`). New service `ApiStudioAccessPolicy` (stateless).
- **Host routes** (`security.host_routes`: route name => `studio.open` | `workspace.create`): host pages that belong to the studio (e.g. a sharing page) go through the same chain as the bundle's own routes — bundle enabled check, tenant resolution (no tenant: 404), roles, access checker, `studio.open`, feature gate, the 403 / 404 policy, `no-store` headers, the tenant SQL filter and the `ui.path` placeholders in the router context. Workspace actions stay in the host controller (`ApiStudioAuthorization::requireWorkspace()`). Route names are compared as written (`app-share` stays `app-share`), and a name that is not a route of the router fails the cache warm-up (`HostRoutesCacheWarmer`), so a typo never leaves a page unguarded. New service `ApiStudioRoutes` (bundle + host routes, `isStudioRoute()` / `actionFor()`).

- Vitest tests for the remaining frontend modules (request console, workbench shell, body tools, endpoint documentation, script runtime, the sandbox frame, worker and server runner entries, utils): TS/JS line coverage goes from 57% to 96%, and the `assets` CI job now runs `test:coverage` with thresholds (95% lines / statements / functions, 85% branches; the frame relay, lockdown, node refusal and require guard stay at 100% lines) (REQ-TEST-003). Tests only; the sandbox runner, frame relay, lockdown and console are untouched.

### Changed

- The tenant SQL filter (`tenancy.sql_filter`) is also turned off when a main request starts, not only when it finishes: a request that never finished in a long-lived worker (fatal path) cannot leave it on for the next one.
- Demo: the shell is UiKit's admin layout (header menu with the active item, UiKit flashes, default theme unchanged); Bootstrap 5, Bootstrap Icons and the CDN assets are gone (REQ-DEMO-014).

### Security

- `pm.sendRequest` (`/scripts/send-request/{id}`) and persisted script variables (`/scripts/variables/{id}`), live only with `scripts.mode: sandbox` (1.15.0), did not subscribe to the request's `ApiStudioAuthorization` and fell back to the tenant-less, everything-granted default: the host permission checker (`workspace.run` / `workspace.edit`), the tenant scope and `visibleWorkspaceIds()` were not applied to the endpoint's workspace (with `tenancy.sql_filter` the endpoint lookup itself stayed scoped to the tenant and visible workspaces). Both controllers now use the request's authorization (403, or 404 under `access_denied_response: 404`). Hosts on `scripts.mode: inline` or `disabled` were not affected. The controllers' authorization and policy lookups now fail closed: a controller whose service locator lacks them throws a `LogicException` instead of using an everything-granted default, and a test checks that every controller subscribes what it calls.

[1.16.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.16.0

## [1.15.0] - 2026-11-17

Target 1.15.0 (spec 003, sandboxed script runner), on top of 1.14.0:

### Added

- **`scripts.mode: sandbox`.** Pre / post request scripts run in an opaque-origin iframe (`sandbox="allow-scripts"`)
  served by `nowo_api_studio_script_sandbox` with its own CSP (`default-src 'none'`, the frame runtime by nonce,
  `'unsafe-eval'` only there, `frame-ancestors 'self'`). Scripts cannot reach the page DOM, the session cookies,
  storage or the network; they talk to the console only through a versioned `postMessage` protocol (source, opaque
  origin and per-run nonce checked). The studio page never evaluates script code in this mode, so it needs no
  `'unsafe-eval'` (it needs `frame-src 'self'`). No stored-script confirmation is shown in this mode.
- `scripts.limits.time_ms` (default 2000): wall time of one sandboxed phase; the frame is removed when it is reached
  and the request fails (pre) or the post scripts are reported as failed.
- `api-script-sandbox.js` (frame runtime) and the shared `pm` facade module.
- **`pm.sendRequest`** (sandbox mode): the frame asks the console, which posts it to
  `nowo_api_studio_script_send_request` (console CSRF token, `workspace.run` on the endpoint's workspace and folder);
  the server sends it literally through the console guards (SSRF guard, `execution_url_allowlist`, connected IP and
  redirects re-checked, no redirects, timeout, response size limit). No variable, stored secret or host secret is
  resolved into it and no studio cookie is sent. At most 10 calls per phase; callbacks and promises finish before the
  next script runs. Not available in `inline` mode.
- `pm.expect` is a chai BDD subset (chains, `not`, `equal`, `eql`, `true` / `false` / `null` / `undefined` / `ok` /
  `empty`, `a`, `include`, `property`, `lengthOf`, `above` / `below` / `least` / `most`, `oneOf`, `match`), in both
  modes; `expect(x).to.equal(y)` and its message are unchanged.
- **Workspace and folder scripts** (sandbox mode only): the workspace and folder forms get pre / post request script
  fields (new nullable `pre_request_script` / `post_request_script` columns on the workspace and folder tables, mapped
  only in sandbox mode). The console runs them in chain order: workspace pre, service pre, folder pre (root to leaf),
  endpoint pre; then endpoint post, folder post (leaf to root), service post, workspace post. Each script's log lines
  are labelled with its level.
- **`pm.globals` / `pm.collectionVariables`** (sandbox mode): `get`, `set`, `unset`, `has`, `clear`, `toObject`.
  Globals belong to the workspace, collection variables to the service; both are stored server-side in a new
  `script_variable` table (mapped only in sandbox mode; plain values, no secret flag) and handed to the console with
  the endpoint page. Changes are kept for the next sends of the page and saved only when "persist variables" is
  checked (`nowo_api_studio_script_variables`: environment-sync CSRF token, `workspace.edit` on the workspace and the
  endpoint's folder; at most 100 changes per save, 500 variables per scope, keys of 128 characters, values of 65,535 bytes). Request templates
  resolve the environment first, then collection variables, then globals; `pm.variables.get` reads in the same order.
- **Server-side script runner** (`scripts.server_runner`, off by default): the port
  `ApiStudioScriptRunnerInterface::run(ScriptRunRequest): ScriptRunResult` (value objects only) for unattended runs,
  `NullScriptRunner` (default: scripts reported as not run) and the reference `ProcessScriptRunner`: one sidecar
  process per run (Deno without permissions by default, or Node) running the bundled
  `Resources/runner/api-script-runner.js` over a line-delimited JSON protocol (the sandbox messages). Limits: wall
  time (killed), output (`scripts.limits.output_kb`, killed), memory (`scripts.limits.memory_mb`, handed to the
  sidecar) and `pm.sendRequest` calls (sent through the same guarded sender as the console). The sidecar's environment
  is scrubbed; no process outlives a run. Uses `proc_open` with bounded, non-blocking reads and writes (no extra
  package; a sidecar that floods its output is killed at the limit without filling PHP memory).
- `scripts.limits.send_requests` (default 10): `pm.sendRequest` calls per phase, now also for the sandbox frame.
- **`require()` allow-list** (sandbox frame and server runner; not in `inline` mode): a closed list bundled into
  `api-script-sandbox.js` and the runner, never into the console page. Anything else throws. 1.15 list (all MIT):
  `ajv` 8.20.0, `chai` (the bundle's `pm.expect` subset, `{ expect }`), `crypto-js` 4.2.0 (deprecated upstream, kept
  for Postman compatibility), `dayjs` 1.11.23 (also as `moment`), `fast-xml-parser` 5.12.1 (`XMLParser`,
  `XMLBuilder`, `XMLValidator`), `lodash` 4.18.1, `uuid` 14.0.3 (`v4`, `v7`, `validate`, `version`, `NIL`).
- **Postman `event[]` import** (sandbox mode, for a user with `workspace.edit_scripts`): `prerequest` / `test`
  scripts of the collection, its folders and requests are stored as the pre / post scripts of the service (only a
  service the import creates), the folders (only folders the import creates) and the endpoints. Disabled and empty
  events are skipped; the import reports how many scripts were imported and how many were not (other modes, missing
  right, existing service or folder, other event kinds).

- **`pm.response` helpers** (every mode): `pm.response.headers` (`get` / `has` ignoring case, `toObject`; the masked
  response headers), `pm.response.responseTime` (ms) and Postman's `pm.response.to.have.status()` / `.header()` /
  `.body()` / `.jsonBody()`, `pm.response.to.be.ok` / `.success` / `.error` / `.clientError` / `.serverError` /
  `.json` and `pm.response.to.not.*`; the same on `pm.sendRequest` responses and in the server runner
  (`ScriptRunRequest` gains optional `responseHeaders` and `responseTimeMs`).
- `ScriptChainBuilder`: the scripts of an endpoint in run order with the console's log labels (workspace, service,
  folders root to leaf, endpoint; the reverse after the response), for runs outside the browser through the server
  runner. Workspace and folder levels are included only in sandbox mode, where they exist.

### Changed

- Spec Kit: the 53 `src/` files added since the 2026-10-30 baseline review are mapped in `code-inventory.md`, with five new area requirements in `spec.md` (REQ-SPECKIT-001 / REQ-SPECKIT-003)
- README: measured TypeScript coverage reported instead of "N/A" (REQ-TEST-007)

### Deprecated

- `scripts.mode: inline` (still the 1.x default): scripts run unsandboxed in the page with the sender's session.
  `sandbox` becomes the default in 2.0 (spec 003 FR-SCR-001); set `scripts.mode` explicitly to keep a mode.

### Security

- Secret values are also masked in their HTML-escaped and base64 / base64url forms (history, execute responses and so
  what post-response scripts see), on top of the raw, URL-encoded and JSON-escaped forms (spec 003 US5).
- **Scripts run in a dedicated Worker of the sandbox frame** (audit RW-M1): CSP cannot stop a document from navigating
  itself, so a script in the frame document could send data out in a URL (`location.href`, an anchor click), in
  every engine and whatever the headers. The frame now starts a Worker from a `blob:` URL (frame policy
  `worker-src blob:`), runs the scripts there (no `document`, nothing to navigate, network globals removed) and only
  relays; the console fails the run if the frame loads a second time or reports a crashed worker. Playwright checks
  location, anchor, form and `window.open` navigation in Chromium, Firefox and WebKit.
- **Server runner** (audits RW-L1, RW-L2): the bundled runner refuses every run under Node below 25, or Node without
  `--permission` (before Node 25 a script reaches the network through `fetch` / `node:http`: SSRF to internal
  services and cloud metadata endpoints), and removes the network globals from the scripts' scope; each
  `pm.sendRequest` call gets only what is left of `scripts.limits.time_ms` and the run stops when it is used up.
  CI runs the runner's integration tests under Deno.
- Defence in depth (audit RW-D-L1): the network APIs are removed along the whole prototype chain of the worker global
  and the frame window (in workers `fetch` / `importScripts` live on `WorkerGlobalScope.prototype`, where an own
  shadow left them recoverable). The CSP remains the primary gate.
- A script-variable save that deadlocks or times out waiting for a lock answers 409 with `Retry-After` instead of
  500 (audit RW-D-I1).
- **Script variables** (audit RW-L3): keys of at most 128 characters, values of at most 65,535 bytes (not
  characters); one save writes globals and collection variables in one transaction after locking the workspace row,
  so the 500-keys cap holds under concurrent saves.
- The sandbox frame no longer depends on its CSP response header alone: the same policy is the first element of the
  frame document's `<head>` (a meta policy that host middleware cannot strip; the dev web profiler removes CSP
  headers, a host CSP listener may replace them), DNS prefetching is off, and the frame runtime removes `fetch`,
  `XMLHttpRequest`, `WebSocket`, `EventSource`, WebRTC (`RTCPeerConnection`, which no CSP governs), workers,
  `navigator.sendBeacon` and `window.open` before any script runs. Found by the extended Playwright corpus: in the dev
  demo the header was stripped and a script's `fetch`, image, `EventSource`, WebSocket and beacon requests reached
  the server (unreleased code, never tagged).
- The console treats the sandbox frame as untrusted: it posts the run once (a repeated `ready` is ignored), accepts
  only a well-formed result whose JSON grows the state it was given by at most 256 KB, refuses `pm.sendRequest`
  messages over 256 KB, and removes the frame (failing the phase) when the frame sends more messages than its
  `pm.sendRequest` budget plus `ready` and `result` (FR-SCR-003, `postMessage` flood edge case).
- Worker test: 1,000 server-runner runs in one process leave no child process and keep memory flat (SC-004).
- Tests now prove that no secret value reaches the script context: the sandbox console page carries secret variables
  only as their `{{placeholder}}` (functional and Playwright), and `pm.sendRequest` sends placeholders literally.

### Fixed

- A MySQL error 1305 is only treated as a lost savepoint (409) when its message names a SAVEPOINT (audit RJ-D3-I2).

[1.15.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.15.0

## [1.14.0] - 2026-11-16

Target 1.14.0 (spec 002, nested folders). Opt-in: with the defaults the schema, pages and importers are 1.13's.

### Added

- **Nested folders** (`folders.enabled`, `folders.max_depth`): `ApiFolder` (parent, sort order, description, auth and
  headers) inside each service, folder create / edit / delete pages (deleting moves subfolders and endpoints up), a
  folder select on the endpoint form and a Folders section on the service page.
- **API tree with folders and drag & drop**: folders nest in the sidebar; with `workspace.edit`, endpoints and folders
  are dragged into folders, before other endpoints or to the service root (`POST …/folders/move`, CSRF header); cycles,
  other services and the depth limit are refused (422).
- **Inherited auth and headers**: an endpoint is sent with the nearest folder's explicit auth (`none` included), else
  the service's; headers merge service → folders → endpoint (case-insensitive, deepest wins). The console names the
  folder the auth comes from. The runner variable protection (ER-M1) covers folder auth and headers.
- **Folder-aware import / export**: Postman item groups become folders (depth capped, merges reported) with their
  collection / folder auth; bearer and header API-key request auth become endpoint headers; scripts are counted, not
  imported. OpenAPI operations land in the folder of `x-api-studio-folder` or of their first tag (tag descriptions
  kept); service exports write both.
- **Folder sharing hook**: `security.folder_permission_checker` (`ApiStudioFolderPermissionCheckerInterface`,
  `ApiStudioFolderRef` with ids, ancestors and the creator id) narrows the workspace decisions per folder.
- `ApiStudioResourceChangedEvent::TYPE_FOLDER` and `ACTION_MOVED` (folder CRUD and tree moves, after commit).
- Folder `created_by` uses a binary NO PAD collation on MySQL (`utf8mb4_0900_bin`) and MariaDB (`utf8mb4_nopad_bin`).
- Tree writes (create, edit, move, delete) lock the service row and check depth and cycles on rows read inside the
  transaction; `folder.parent_id` has no ON DELETE action; contention answers 409 with `Retry-After`.
- Imports never reuse a folder the user may not edit, set auth only on folders they create, keep literal request
  credentials out of endpoint headers, and clean imported names (control, zero-width and bidi characters).
- Inside a folder, the ER-M1 and host-secret console gates also need `workspace.edit` on the folder.
- The tenant SQL filter covers folders. Deleting a folder emits `ACTION_MOVED` for the content it moves up.
- Service / workspace deletes remove their folders in the same flush (no managed folder left behind); imports into a service take the
  service lock; a failed rollback closes the connection; a folder deleted meanwhile answers 404.

[1.14.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.14.0

## [1.13.2] - 2026-11-16

### Security

- **Stored scripts are confirmed before they run (`scripts.confirm_stored`, default `true`).** Pre / post request
  scripts stored on a service or an endpoint run in the request console of whoever sends the request, in the page and
  with that user's session. The console now shows the code of the stored scripts this browser session has not
  approved and asks to run them, send the request without scripts, or cancel. An approval is kept in
  `sessionStorage` per SHA-256 of the code, so any change asks again; code the user typed in an empty script field is
  not asked. The dialog shows each script whole, with its line and character count, makes invisible and
  direction-changing characters visible, and flags code built at run time. Send is disabled while a request or the
  dialog is pending, and the approved code is the code that runs. `scripts.confirm_stored: false` restores the
  previous behaviour. `scripts.mode: disabled` is unchanged.

### Changed

- The `scripts.mode` configuration help no longer announces a sandboxed runner for 1.13; it is planned on the roadmap
  (spec 003). `docs/SECURITY.md` describes the confirmation and the remaining residual risk.

[1.13.2]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-11-15

Target 1.13.1

### Fixed

- **Workbench squeezed into one narrow column in grid hosts.** The workbench (API tree + page content) is rendered
  inside UiKit's `cards` region (dashboard) and `show_sections` region (workspace / service / endpoint pages). Hosts
  style those regions as grids (for example `grid gap-4 lg:grid-cols-2`), so the two-column `.as-workbench` landed in a
  single cell: its main column shrank to about 200px and the project cards stacked. `.as-workbench` now spans the whole
  region (`grid-column: 1 / -1`, full width in flex hosts); the host needs no CSS override.

[1.13.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-11-15

Target 1.13.0

### Added

- **Host secret source for secret variables.** New framework-free port
  `Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface::resolve(ApiStudioSecretRef): ?ApiStudioSecretValue`
  with its own value objects, enabled with `nowo_api_studio.secrets.source: <service id>`. A *Secret* variable whose
  value is `{{secret:<source>/<id>}}` (e.g. `{{secret:vault/payments-stripe}}`) is resolved on the server at execute
  time, per execution and per actor / workspace / tenant (the host port decides; `ApiStudioSecretRef` carries the ids).
  The value is sent to the request target only: it is masked in the execute answer (including the error message), the
  history (including the error message), every log record of the execution and the executed event, and it is never
  stored or exported. A reference the host does not resolve stops a request that needs it (422,
  `error_code: secret_unresolved`, `unresolved_variables`). Console overrides are never resolved. Nothing is memoised
  (FrankenPHP worker mode, kernel kept alive). Without `secrets.source` the behaviour is unchanged. See USAGE
  "Host secrets (1.13)". Hardened after audit QE:
  - `ApiStudioSecretRef::$targetOrigin`: the request origin before substitution (IDN in punycode, no trailing dot), so
    the host binds each secret to its destinations; the bundle refuses to send if the origin changed, if a `Host`
    header names another authority, or if a WSDL's `soap:address` is on another origin (422 `secret_target_changed`)
    (QE-B-M1, QE-D-M1, QE-D-L1, QE-D-I1).
  - A runner without `workspace.edit` cannot use a host secret in a body, header or query template they changed
    (422 `override_not_allowed`); line-ending noise (CRLF, one leading or trailing line break) is not a change
    (QE-B-M2, QE-D-L2).
  - Masking also covers base64 (incl. Basic `user:secret`), HTML entities and `\u` escapes (QE-B-L1); values under
    8 bytes are refused (QE-B-L5); ids with `..`, a leading `/` or an empty segment fail closed (QE-B-L6);
    `ApiStudioSecretValue` keeps the value outside its properties (QE-B-L3); no redirect is followed for a request that
    carries a host secret (QE-B-L4). The framework `http_client` log channel is documented (QE-B-L2).

[1.13.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.13.0

## [1.12.4] - 2026-11-15

Target 1.12.4

### Security

- **Secret values no longer leak through transport error messages (QE-A-M1, since 1.11.0).** Symfony transport errors
  quote the full request URL (`Connection timed out … for "https://host/path?api_key=…"`). `/execute` returned that
  message unmasked in `error_message`, stored it in the history row and logged it in the `execute_failure` record, so a
  runner with `workspace.run` but not `workspace.edit` could read a stored secret used in the URL path or query. The
  error message is now masked with every secret value of the request (raw, URL-encoded, JSON-escaped, and as the HTTP
  client normalises a URL path or query — `ab c+d/12` → `ab%20c+d/12`) in the answer and the history, and `execute_failure` logs the exception class and the host only. Intended change: for requests that
  carry secrets, `error_message` now shows `[REDACTED]` where it showed the value.

### Fixed

- **Sidebar tree: no interactive element nested in the workspace toggle (AS-A11Y-1).** `_sidebar_tree.html.twig`
  rendered each workspace row as a `role="button"` / `tabindex="0"` row that wrapped the workspace link, which axe
  reports as `nested-interactive` (serious). The toggle is now only the chevron: `<button type="button"
  class="as-tree-chevron" data-tree-toggle aria-expanded aria-controls aria-label>` (label `sidebar.toggle_workspace`,
  7 locales), with the link next to it and `id="as-tree-ws-<id>"` on the children. `api-studio-shell.js` keeps
  `aria-expanded` in sync with the group; Enter / Space are the button's own. Overrides still on the old markup keep
  working (see UPGRADING).
- **`scripts.mode: disabled` no longer breaks the request console (AS-SCRIPTS-1).** `api-tester.js` returned before
  wiring Send when `window.ApiStudioScriptRuntime` was missing, so with no runtime on the page (disabled mode) nothing
  could be sent. It now runs without the runtime: pre/post scripts are skipped and the script tabs stay hidden.
  `#api-studio-tester` carries `data-scripts-mode` (`inline` / `disabled`); with `disabled` the console never runs
  scripts, even when an override or the host layout still loads `api-script-runtime.js` (QE-A-L1).

### Added

- Vitest suite for the console scripts (`pnpm run test`, jsdom + axe-core) and a CI job that runs it, type-checks and
  checks that the committed `src/Resources/public/*.js` match a rebuild.

[1.12.4]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.12.4

## [1.12.3] - 2026-11-13

### Fixed

- **Unit boundary tracker: per-worker message depth, request stack (audit NX CR1–CR3).** The tracker kept one
  process-wide "message in flight" flag and judged a main request outermost without looking at other main requests.
  A Messenger worker run in-process inside a message handler ended the outer message, so the inner worker's messages
  and a main request handled later by the outer handler cleared the outer unit (CR1); a main request handled
  in-process inside another main request cleared the outer one (CR2); a message skipped by a
  `WorkerMessageReceivedEvent` listener (`shouldHandle(false)`) stayed in flight until the worker stopped (CR3). It now
  keeps a stack of the running workers (`WorkerStartedEvent` / `WorkerRunningEvent` / `WorkerStoppedEvent`), each with
  its own message, and a stack of the main requests in flight, popped by their own `kernel.finish_request` /
  `kernel.terminate` (unbalanced events heal); a skipped message ends with its received event or at its worker's next
  `WorkerRunningEvent`. Message boundaries clear only outside another message. No configuration change.
- **Unit boundary tracker: aborted worker runs, skip read, nested terminate (audit OB).** A Messenger run aborted by an
  exception thrown out of `Worker::run()` (a throwing received, handled or running listener) left its worker "in
  flight", so inside a long-running command every later worker's messages stopped clearing (OB-M1) and the worker stack
  grew with every aborted run (OB-L1); the tracker now holds weak references to the worker and to the received event of
  the message in flight, and drops a run as soon as either is gone. The `shouldHandle(false)` read at the end of a
  received event no longer ends an outer message when a listener already ended the skipped one (OB-L2). The
  `kernel.terminate` of a main request handled in-process inside another one no longer clears the outer request nor
  resets the tracker (OB-L3). Note: in hosts with Messenger, DoctrineBundle's own subscriber clears every manager after
  each message, so a worker run inside a handler keeps the bundle's pending events and memos, not the identity map.
  Audit OB2-L1: a main request also drops the requests left in flight when the oldest one is not the kernel's main
  request (host code left the `RequestStack` unbalanced, then a request threw out of `HttpKernel::handle()`), so the
  next requests keep clearing instead of never clearing again for the rest of the worker's life.
- DoctrineBundle 3.4: bundle entities resolved to the default manager when a dedicated manager is configured.
  DoctrineBundle 3.4 `auto_discover_entities` (on by default for a manager without `mappings` and `auto_mapping`, with
  Symfony 7.3+ and doctrine/orm 3.6+) gives that manager's metadata driver chain a default attribute driver, which
  claims every `#[ORM\Entity]` class. With `nowo_api_studio.entity_manager` set, `getManagerForClass()` then answered
  the host's default manager, and the Api Studio repositories queried its database ("no such table"). The compiler
  pass now also registers a driver that maps nothing for the Api Studio entity namespace on every other manager
  (`ExcludedEntityNamespaceDriver`, only the public `addDriver()` of the `doctrine.orm.<manager>_metadata_driver`
  chain), so the entities resolve to the configured manager on DoctrineBundle 2.x, 3.3 and 3.4+. Affected in practice:
  1.12.0 to 1.12.2 (every release with `nowo_api_studio.entity_manager`; all allow `doctrine/doctrine-bundle` `^3.0`)
  with DoctrineBundle 3.4.0 or later.

[1.12.3]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.12.3

## [1.12.2] - 2026-11-12

### Fixed

- **Console boundary counter could leak in a long-lived process (FrankenPHP worker, kernel reset false; audits NM and
  NL2-v).** `EntityManagerBoundarySubscriber` counted running commands with `++` on `console.command` (priority 4096)
  and `--` on `console.terminate` (priority -4096). A terminate listener in between that threw or stopped the
  propagation, or a `console.error` listener that threw (Symfony then skips `console.terminate`), left the process
  "inside a command" for good, and the bundle-owned entity manager was then never cleared again between HTTP requests
  (stale workspaces across requests and tenants). A `console.command` listener above 4096 that stopped the propagation
  in a nested command made the nested end clear the outer unit mid-run. Each console run is now a frame on a stack keyed
  by its `Command` object and its input (a command that runs itself in-process is a new frame), pushed at
  `console.command` priority `PHP_INT_MAX` with the outer state it started in (message in flight, main requests in
  flight) and removed, restoring that state, by its own `console.terminate` at priority `PHP_INT_MAX`; a terminate
  without a frame changes nothing. `console.error` (priority `PHP_INT_MAX`) marks the frame as failing: when an error
  listener throws, the frame is dropped at the next main request, message, command or worker stop. The outermost clear
  still runs at `console.terminate` -4096. Main requests are tracked per `Request`, so a nested main request no longer
  ends the outer one early, and the end of a main request or message (handled, failed, `WorkerMessageSkipEvent`,
  `WorkerStoppedEvent`) restores the frames it started with. The Messenger and `kernel.terminate` bookkeeping also runs
  at `PHP_INT_MAX` (the clears keep their priorities), a main request also saves the message state, and a top-level
  `kernel.terminate` forgets the requests whose end never ran (a `kernel.exception`, `kernel.finish_request` or
  `kernel.terminate` listener threw), so no `Request` is kept and a later top-level command clears again (audit NR-a).
  Tests run a real console `Application` and `HttpKernel` on one dispatcher without the services resetter.

[1.12.2]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.12.2

## [1.12.1] - 2026-11-12

Target 1.12.1 (patch).

### Fixed

- **The dedicated entity manager is cleared at every boundary, also without the services resetter (REQ-RUNTIME-002
  rule 4).** With `entity_manager` set (1.12.0) the Api Studio manager was never `clear()`ed by the bundle: only
  DoctrineBundle's `kernel.reset` did it. **Precondition:** with the stock Symfony kernel the services resetter still
  runs before every request (also with `FRANKENPHP_RESET_KERNEL=false`), and DoctrineBundle clears every manager after
  each Messenger message, so nothing leaked there. It leaked only with `entity_manager` set and where the resetter does
  not run: a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), and
  long-running console commands. There, workspaces, services, endpoints and environments loaded by one request stayed
  managed: later requests of any user or tenant were served stale names, URLs and variable values (another tenant's
  rows were never shown: every load re-checks the tenant scope), and the identity map grew with usage. The new
  `EventListener\EntityManagerBoundarySubscriber` recreates the bundle-owned manager when a failed write closed it and
  clears it on the main `kernel.request` and `kernel.terminate` (outside a Messenger message and a console command: a
  page rendered in-process by a handler or a command keeps the outer unit of work), on Messenger message received / handled / failed, and
  on the start and end of the outermost console command (a command run in-process from another command, a message
  handler or a controller keeps the unit of work, and the pending changes, of the unit that runs it). An open manager
  is never replaced; changes not flushed by the end of a unit are discarded, as in a PHP-FPM request. It never touches the host's manager: without `entity_manager`, or with `entity_manager` naming the
  host's default manager, clearing is still the host's job. Test: `Tests\Functional\Worker\WorkerStateTest` (one
  kernel without the resetter, two tenants with two users each over 500 iterations: 2,000 requests and 1,000 messages,
  memory within 4 MiB).
  **Affected:** 1.12.0 with `entity_manager` set to a non-default manager, only under the precondition above (services
  resetter removed, replaced or bypassed, or `http_kernel` driven directly, or a long-running console command).
- **Dev dependency:** `symfony/messenger` (worker test only; the bundle does not require it).

[1.12.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.12.1

## [1.12.0] - 2026-11-11

New doctrine entity_manager option (entities only on that manager), after-commit workspace and resource events with IDs only, request-executed event without secrets, a request-free tenancy provider port; secret encryption no longer crashes on other managers. See [UPGRADING](https://github.com/nowo-tech/ApiStudioBundle/blob/v1.12.0/docs/UPGRADING.md#to-1120).

### Added

- **`entity_manager`** option: map the Api Studio entities (and the tenant SQL filter) on a dedicated Doctrine entity
  manager only — also when the default manager has `auto_mapping: true` — and make every read, write, flush and reset of
  the bundle use it. The build fails when the manager does not exist. Default `null`: unchanged.
- **`ApiWorkspaceCreatedCommittedEvent`** (ids only), dispatched after the workspace creation committed; the existing
  in-transaction `ApiWorkspaceCreatedEvent` stays for atomic host writes.
- **`ApiStudioResourceChangedEvent`** after every flushed create / update / delete / import (workspaces, services,
  endpoints, environments, environment variables, request / response examples) and **`ApiRequestExecutedEvent`** after
  each console execution (ids, method, host, status, duration; no path, query, headers, bodies or values).
- `ApiWorkspaceCreatedEvent::$workspaceId`, `$slug`, `$actorId` and `ApiStudioSubject::$workspaceId` (entities kept).
- **`tenancy.provider`**: request-free `ApiStudioTenantProviderInterface::current()` as an alternative to
  `tenancy.resolver`.
- USAGE: "Events" and "Using it from a DDD / hexagonal host" sections; kernel tests for a standard host, two managers with
  `auto_mapping`, a host without `default_entity_manager`, a missing manager and a kept-alive (worker) kernel.

### Changed

- The controllers, importers and the demo seeder no longer autowire the host's default `EntityManagerInterface`: they get
  the manager of the Api Studio entities (`entity_manager`, else the one that maps them — the default manager in a
  standard host, so nothing changes there). The recovery reset after a failed flush and the tenant filter use the same
  manager.

### Fixed

- `SecretVariableEncryptionListener::onFlush()` read the metadata of `ApiEnvironmentVariable` on every flush of every
  manager; a flush of a manager that does not map the Api Studio entities threw a `MappingException`.

[1.12.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.12.0

## [1.11.0] - 2026-11-04

### Added

- **Tenants** (`tenancy.enabled`, `ApiStudioTenantResolverInterface`): every workspace belongs to a tenant resolved per
  request; the dashboard, lists, counts, the sidebar and every lookup are scoped to it; the workspace slug is unique per
  tenant. Opt-in mapping: without it the schema is unchanged.
- **Per-workspace, per-action permissions** (`security.permission_checker`, `ApiStudioPermissionCheckerInterface`,
  `ApiStudioAction`, `ApiStudioRouteActions::MAP`): not viewable → 404, not granted → 403, forbidden actions hidden in
  every page; Twig `nowo_api_studio_can()`; script fields gated by `workspace.edit_scripts`.
- `ApiWorkspaceCreatedEvent` (inside the creating transaction) and `ApiWorkspaceDeletedEvent`.
- **Tenant SQL filter** (`tenancy.sql_filter`, default true with tenancy): defence in depth that narrows every bundle
  query of an Api Studio request (workspaces and every row under them) to the tenant and the visible workspaces.
- **Tenant commands**: `nowo:api-studio:tenant:orphans` (read-only), `nowo:api-studio:tenant:move <id> --to-type
  --to-id [--dry-run]` (`ApiWorkspaceMovedEvent`), `nowo:api-studio:tenant:unsuffix-slugs --tenant-type --tenant-id
  [--dry-run]`.
- **Slugs**: an empty slug is derived from the name; a new or edited slug must match `^[a-z0-9]+(?:[-_][a-z0-9]+)*$`
  (≤ 128 characters). Existing slugs of another shape stay valid until they are edited.
- **Placeholders in `ui.path`** (`/organizations/{organization}/api-studio`) with `ui.path_requirements`; the bundle's
  own links carry the current values (router context, restored after each request).
- `ui.locale_scope` (`routes` | `global` | `none`), `history.scope: user` (+ `ApiStudioActorResolverInterface`,
  opt-in `executed_by` column), `scripts.mode: disabled`, `seed-demo --tenant-type/--tenant-id`.

### Changed

- **`ui.locale_scope` defaults to `routes`**: the Api Studio locale no longer replaces the locale of the host's own
  pages (a fix; set `global` for the 1.10 behaviour).
- Form placeholders are translation keys with `attr_translation_parameters` instead of `StaticMessage` objects (host
  form themes can test them as strings); the rendered text is unchanged.
- `routes.yaml` delegates to a route loader (type `nowo_api_studio`); route names, paths and the import are unchanged.

### Fixed

Follow-ups of the 1.10.1 security review (Appendix EQ):

- **EQ-I1**: secret values are always encrypted from the value as typed (imports, env sync and scripts included), never
  passed through when they already look like a payload. Stored payloads therefore only come from the cipher, and a
  typed value shaped like a payload reads back as typed instead of being flagged *needs re-entry*. With
  `secrets.encrypt: false`, such a value is still encrypted, so it reads back as text.
- **EQ-I3**: pasting the stored (foreign) payload into an unreadable secret keeps it unreadable.
- **EQ-I2**: a console override of `''` or of a placeholder (`{{ token }}`, spaced or not) does not stand in for an
  unreadable secret: the request is still not sent. The secret marker check accepts the spaced form too.
- **EQ-I6**: the slug race safety net recognises the slug unique index by its name (or its `table.column` list on
  SQLite), not by the word "slug" in the driver message.
- **EQ-I7**: `nowo:api-studio:secrets:status --tenant=type:id` (empty: the instance tenant) reports one tenant only.
- **ER-M1** (1.11 review): without `workspace.edit`, `/execute` refuses (422 `override_not_allowed`, logged with ids
  only) any change to a variable used in the URL, the auth configuration or a header that carries a secret, so a
  runner cannot send the stored secrets to a host of their choice.
- **ER-L1**: `symfony/yaml` now requires `^7.4.23 || ^8.1.11` (YAML parse-time advisory GHSA-hwfg-qwvh-2wfx, reachable
  through YAML imports); the lock is updated and `composer audit` is clean.
- **ER-I1**: the console's JSON routes answer a refused action with `{"error":"forbidden"}` (403).
- **ER-I2**: default history actor ids are namespaced (`u:` + identifier, `h:` + hash) and a host resolver's id must be
  1–64 printable characters.
- **ER-I3**: `/execute` answers 400 for an environment that is unknown, hidden by the tenant filter or of another
  workspace.
- **ER-I6**: the route scope listener resets the router context between worker requests; `scripts.mode: disabled` does
  not load `api-script-runtime.js`.

[1.11.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.11.0

## [1.10.1] - 2026-11-04

### Fixed

- **Duplicate slugs give a form error (422), never a 500.** Workspaces (unique in the instance), services and
  environments (unique in their workspace) and endpoints (unique in their service) carry a `UniqueSlug` class
  constraint: the form shows "The slug … is already used here" with a 422. That includes saving a second new workspace
  with the default `new_workspace` slug. A slug taken by another request between validation and flush is caught on the
  unique index and shown the same way.
- **A secret that cannot be decrypted fails closed.** A stored `nowo_as_enc_v1:` payload that the configured key cannot
  open (another key, for example after `APP_SECRET` changed, or a corrupt row) was kept in memory as the variable's
  value: it was sent upstream as the secret, exported, and wrapped in a second encryption on the next save. Now the
  variable is flagged *needs re-entry* (`ApiEnvironmentVariable::isUnreadable()`), its value is `''`, and the payload is
  never handed out:
  - a request that references it (base URL, path, query, headers, body or auth configuration) is **not sent**: the
    console gets a 422 with `error_code: secret_unreadable` and the variable names, and the failure is in the history;
    a value typed in the console for that variable is used, and requests that do not reference it run normally;
  - saving the environment keeps the stored payload byte-identical (the empty write-only field means "unchanged");
    entering a new value replaces it; unchecking *Secret* is refused until the value is entered again;
  - JSON / YAML exports write `"value": null, "needs_reentry": true`, the `.env` export a comment line instead of an
    assignment; importing such a row changes nothing;
  - the environment page, the variable catalogue and the variable form show *Needs re-entry*; a warning is logged with
    the variable and environment ids (no value).
  A row that an earlier release wrapped twice is unwrapped when the key opens the inner payload, and flagged
  otherwise. With `secrets.encrypt: false`, payloads written while encryption was on are still decrypted (or flagged)
  on load instead of being used as values.

### Added

- `nowo:api-studio:secrets:status [--format=text|json]`: read-only report of the secret variables (plain, encrypted,
  cannot be decrypted, with the ids of the latter, never values). Exit code 1 when any secret cannot be decrypted, for
  deploy checks.

[1.10.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.10.1

## [1.10.0] - 2026-10-30

UiKit page contract, opt-in routes, 99.9% coverage gate, FormKit page types; security: .env export no longer executes stored values (GHSA pending), secrets as placeholders, response size cap, literal security switches. See [UPGRADING](UPGRADING.md#to-1100).

### Security

- **`.env` export ran commands when loaded (M1, GHSA, affects 1.0.0 – 1.9.2).** Values were written unquoted or double-quoted, so Symfony Dotenv (or a shell sourcing the file) executed `$(…)` and expanded `${…}`; line breaks in descriptions or environment names injected extra `KEY=value` lines (e.g. `APP_SECRET`). Values imported from third-party files, Postman collections or persisted by console scripts could carry them. Every value is now single-quoted (or double-quoted with `\\`, `"`, `$`, backtick and line breaks escaped), comment lines carry no control character and keys that are not valid environment variable names are skipped; tests round-trip hostile values through `Dotenv::parse()`.
- **Secrets no longer reach the browser (L1).** The request console receives `{{name}}` placeholders for secret variables (the server resolves them on execute); execute answers mask secret values in the request and in echoed responses; the edit form shows secrets as an empty password field (empty = unchanged); every Api Studio response is `Cache-Control: no-store, private`. Exports still contain secret values (documented).
- **`security.allow_unauthenticated` could fail open (L3).** An `%env()%` placeholder was read as `true` at compile time. `enabled`, `security.allow_unauthenticated` and `execution_url_allowlist_required` must now be literal booleans, and `allow_unauthenticated: true` is refused unless `kernel.debug` is true and never in the `prod` environment.
- **Response size cap (L2):** new `execution_max_response_bytes` (default 10 MiB) for REST, SOAP, raw SOAP and every WSDL / XSD document; a bigger transfer is cancelled and reported.
- **Secret values starting with the cipher prefix (L4)** were stored in clear and broke every load of their environment (HTTP 500). They are encrypted like any value; a row the key cannot decrypt is loaded as stored.
- **SOAP credentials (L5)** are sent only to SOAP locations on the service (WSDL) origin, like the WSDL imports.
- **History (L6):** secret values echoed by the upstream are masked in the stored response headers and body; URLs over 2048 characters are truncated instead of failing the history row.
- **Imports (I5):** documents with more than 100,000 values (YAML alias bombs) are refused.
- **Deletes (I2):** deleting a workspace is type-to-confirm and the name is checked on the server, like services.
- **Input lengths (I4):** example names over 128 and documentation titles over 255 characters answer 400 instead of a database error.
- Demo: the web port binds to `127.0.0.1` only.

### Changed

- **Every form type is a FormKit type** (REQ-TWIG-005 rule 11): `ClientSideFieldsType`, `KeyValueRowType` and `CsrfOnlyType` join the editors on the `api_studio` profile (`#[FormKitConfig]` + `FormOptionsTrait`); field names, ids and the `csrf_token_id` of the delete forms are unchanged. A test keeps every `src/Form/*Type.php` FormKit-integrated.
- Requires `nowo-tech/ui-kit-bundle` `^2.17.2` and `nowo-tech/form-kit-bundle` `^2.14` (the page-contract view models and FormKit options the pages use).

### Added

- **UiKit page contract (REQ-UI-003).** Every page extends a UiKit scaffold (`page/dashboard`, `page/list`, `page/show`, `page/form`) fed by UiKit view models (`Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory`); the host themes them once in `nowo_ui_kit` (`layouts.admin`, `theme`, `css_framework`). Breadcrumbs, back links, KPI tiles, pagination, empty states, row actions and the delete zone come from UiKit. The API tree and the request console are the workbench content of the dashboard and show pages (`_workbench.html.twig`). Page contract test (`PageContractTestCase`, 28 fixtures). See [TEMPLATES.md](TEMPLATES.md).
- `nowo_api_studio.ui.layout_template: null` (new default) = `nowo_ui_kit.layouts.admin`; a pre-1.10 layout (only `{% block body %}`) is wrapped in a deprecated bridge.
- Translated labels for every editor field (workspace, service, endpoint, environment, variable) in the 7 catalogues; the FormKit `api_studio` profile only applies help / placeholder keys that exist (`convention_requires_translation`).

### Changed

- **No CDN, no own HTML shell:** the Tabler / Google Fonts layout (`layout.html.twig`, `base.html.twig`), the rail, the top bar, the theme toggle and the bundle locale menu are removed; the host (UiKit) layout owns the chrome, the UiKit theme owns light / dark. `api-studio-theme.css` maps its colours to the `--nowo-ui-*` tokens and only styles the workbench.
- Deletes (workspace, service, endpoint, environment) go through the UiKit confirm contract (`<dialog>`; the service delete is type-to-confirm); the service export / import / delete modals and the save / delete example dialogs are UiKit native `<dialog>`s; the service menu is the UiKit kebab. No inline `onsubmit` / `confirm()` / inline script remains (strict CSP); deleting a saved example no longer uses `window.confirm()` and a documentation save error no longer uses `window.alert()`.
- Flash messages are translated by the controllers (the UiKit layouts print them as given); invalid form submissions answer HTTP 422.
- The FormKit `api_studio` profile no longer adds Bootstrap classes (`form-control`, `form-select`, `mb-2`…): the host form theme does; script / JSON fields use the semantic `as-code-input` class instead of `font-monospace`. Method / protocol / auth choices print their values instead of untranslated enum case names.

### Deprecated

- `nowo_api_studio.ui.css_framework` (now `null` by default): set `nowo_ui_kit.css_framework`. When set it still seeds UiKit / FormKit.
- A `ui.layout_template` that does not render the UiKit canonical blocks (bridge removed in 2.0).

### Fixed

- **Routes no longer load without the host's import.** On Symfony ≥ 7.3 the controllers (which carry `#[Route]`) were autoconfigured with `routing.controller`, so a host with the default `config/routes.yaml` (`controllers: { resource: routing.controllers }`) got every Api Studio route without importing the bundle routes — bypassing the opt-in import, its prefix and `when@` guards. The controllers are now registered with `autoconfigure: false` (`controller.service_arguments` + `container.service_subscriber` only).
- Imports crashed with "Mime component is not installed": `symfony/mime` (used by the upload `File` constraint) is now required.
- Invalid JSON in a headers / query textarea, an empty or invalid variable key, a new variable row or an empty variable value no longer answer HTTP 500 (form errors instead).
- The workspace `.env` export listed no variable (header line only).
- Table prefixes on join tables crashed on Doctrine ORM 3 (latent: no bundle entity has a join table).
- The service delete from the edit page always failed the name check (the form posted no `confirmation`).
- Saved-example buttons in the request console set their translated labels as text, not HTML.
- README / INSTALLATION: the route import is `@ApiStudioBundle/Resources/config/routes.yaml` (the bundle alias), not `@NowoApiStudioBundle/...`.

### Tests

- PHP line coverage gate: every `src/` class is measured (the 30 `phpunit.xml.dist` exclusions are gone); CI fails below 99%. Functional tests for every controller, unit tests for importers, exporters, entities, repositories, commands and the DI extension.

[1.10.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.10.0

## [1.9.2] - 2026-10-29

ApiStudio: schema sync no longer drops host tables See [UPGRADING](UPGRADING.md#to-192).

### Security

- **The API Studio schema sync could drop host tables.** With Doctrine ORM 3, `SchemaTool::getUpdateSchemaSql()` compares the whole database with the bundle entities, so the sync SQL contained `DROP TABLE` (and on PostgreSQL `DROP SEQUENCE`) for every non-bundle table and sequence unless the host had a Doctrine `schema_filter`. `SchemaSyncService` now compares only the API Studio tables. Every version with `SchemaSyncService` is affected; do not run the schema sync with an older version on a database that holds other tables (CWE-1188).

[1.9.2]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.9.2

## [1.9.1] - 2026-10-28

ApiStudio: the request console response is readable in the light theme. See [UPGRADING](UPGRADING.md#to-191).

### Fixed

- **Request console contrast.** `.as-response-pre` (response body, headers, script console) set a background but no
  text colour and inherited a light one: in the light theme the response was almost invisible. It uses `--as-text`.

[1.9.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-10-28

Performance: the sidebar tree is built from one query instead of one per workspace / service / endpoint. See [UPGRADING](UPGRADING.md#to-190).

### Performance

- **Sidebar tree in one query.** The navigation tree rendered on every studio page walked the lazy collections
  (workspaces → services → endpoints → request examples): 1 + W + S + E queries per request. It is now built from one
  scalar query (`ApiWorkspaceRepository::navigationRows()`), same order and content; a functional test pins the
  query count.

[1.9.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.9.0

## [1.8.1] - 2026-10-28

Security: service delete redirects to local paths only (no protocol-relative open redirect). See [UPGRADING](UPGRADING.md#to-181).

### Security

- **Service delete redirect accepts local paths only.** `ApiServiceController::resolveDeleteRedirect()` followed any
  posted `redirect` starting with `/`, including protocol-relative `//host` and `/\host` (open redirect after a
  CSRF-protected delete). It now accepts only same-origin paths without control characters and otherwise returns to
  the workspace page.

[1.8.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-10-28

Security: empty role lists deny instead of granting everyone; CSRF fails closed without form CSRF protection. See [UPGRADING](UPGRADING.md#to-180).

### Security

- **Empty role lists deny (P1):** `ConfigurableApiStudioAccessChecker` and `ApiStudioAccessSubscriber` no longer treat an
  empty `security.access_roles` (with an empty legacy `ui.required_roles` fallback) as "no check": an empty list grants
  nothing. `ApiStudioSecurityPass` now fails compilation when the effective role list is empty and neither
  `security.access_checker` nor `security.allow_unauthenticated` is set. A role list without SecurityBundle also denies.
- **CSRF fails closed (P2):** with `enabled: true`, `ApiStudioSecurityPass` refuses to compile without
  `security.csrf.token_manager` and `form.type_extension.csrf`, or when `framework.form.csrf_protection` is false.
  Before, `CsrfOnlyType` delete actions and entity forms accepted any POST on such hosts.
  See [UPGRADING](UPGRADING.md#unreleased).

[1.8.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-170).

### Changed

- **Developer tooling (REQ-MAKE-002):** `make release-check` runs the full REQ-MAKE-002 chain in order (ensure-up, co-author and open-PR gates, check-twig-extra, validate-translations, composer-sync, cs-fix, cs-check, rector-dry, phpstan, igor, test-coverage, demo release-check, test-ts). `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`, REQ-TWIG-005) now runs in the chain.
- **Views (REQ-TWIG-005):** every form control is a Symfony Form view — delete buttons (`CsrfOnlyType` via `api_studio_csrf_form()`), sidebar filter and service export / import / delete modals (`api_studio_tree_filter_form()`, `api_studio_service_modal_forms()`, `ImportFileFormType`), and the endpoint tester controls (`testerForm` from `EndpointTesterFormFactory`, unmapped client-side fields keeping the script ids). Delete actions validate through the Form (`handleRequest` + `isValid()`). `_delete_confirm.html.twig` now takes `csrf_token_id`; see [UPGRADING](UPGRADING.md#unreleased).

[1.7.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.7.0

## [1.6.1] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-161).

### Changed

- **Twig (REQ-TWIG-003):** the workspace, service and environment forms (`workspace/form`, `service/form`, `environment/form`) and the import form (`import_export/import`) now render their fields through the child loop (`{% for child in form %}` + `form_row(child)`) instead of `form_widget(form)` / one `form_row(form.x)` per field; the import submit button stays in the actions bar and the endpoint form loop skips already-rendered children. Same fields, order and markup.

[1.6.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.6.1

## [1.6.0] - 2026-10-21

Paginated workspace list (ui.list_page_size), CI static analysis and coverage, translation key-parity gate, browser-script CSP guidance. See [UPGRADING](UPGRADING.md#to-160).

### Added

- `nowo_api_studio.ui.list_page_size` (default `50`): the workspace list is paginated (`?page=N`, UiKit pagination partial) and the dashboard shows at most that many workspace cards with a "View all" link; dashboard totals use `COUNT` queries instead of loading every service and endpoint (REQ-PERF-001).

### Changed

- `make validate-translations` also checks key parity of every catalogue against `en` (REQ-MAKE-004); `release-check` runs it early and ends with `test-ts` (TypeScript type check) (REQ-MAKE-002); `make help` follows the standard block order (REQ-MAKE-001).
- CI: new static-analysis job (PHPStan, Rector dry run, Igor) and the coverage step runs again on PHP 8.4 + Symfony 7.4 (it was guarded by a Symfony 7.0 cell that no longer exists) (REQ-CS-006, REQ-CI-001).
- `docs/SECURITY.md`: browser pre/post scripts (`new Function`) and CSP guidance (REQ-UX-001).
- Tests for `GuardedWsdlLoader` import cycles, the document cap and redirects to blocked hosts; README publishes the measured PHP coverage (configured surface and whole `src/`) (REQ-TEST-007).
- Root `.gitignore` archive block uses the canonical `# Archives / compressed` header; the co-author check accepts git worktrees.

### Fixed

- Igor reports no KO: `GuardedWsdlLoader` is a per-call object (created with `new` for one SOAP call), documented with `@igor-ignore` reasons (REQ-CS-008).

[1.6.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.6.0

## [1.5.2] - 2026-10-21

Dependency refresh (bundle and demos), php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-152).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

[1.5.2]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.5.2

## [1.5.1] - 2026-10-20

Documentation: wave-1 REQ compliance (README, CONTRIBUTING, issue templates, Spec Kit inventory). See [UPGRADING](UPGRADING.md#to-151).

### Changed

- Documentation: wave-1 REQ compliance — README documentation list order (PSR link moved to Additional documentation), canonical issue templates, CONTRIBUTING Cursor Agent settings (REQ-IDE-006), tables of contents in long docs, demo `.env.example` MySQL variables, Spec Kit inventory refresh (128/128).

[1.5.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-19

Endpoint translations use FormKit 2.13 locale_tabs through the UiKit 2.5 partial, the show page uses the UiKit locale_tabs partial, and delete buttons open a UiKit confirm dialog (no inline onsubmit); requires FormKit ^2.13 and UiKit ^2.5.1. See [UPGRADING](UPGRADING.md#to-150).

### Changed

- **Endpoint form translations** (create / edit) render through FormKit 2.13 `locale_tabs` (mode `entries`) and
  UiKit 2.5's `locale_tabs` partial: one tab per `ui.locales` entry, `ui.default_locale` first and marked `★`, the
  locale child a hidden input, WAI-ARIA tabs with arrow / Home / End keys, invalid tabs marked `!` and opened first,
  `aria-invalid` on invalid widgets, stacked fieldsets without JavaScript, theme slots `tabs` / `tab` / `tab_active` /
  `tab_panel`. Translation entries use `error_bubbling: false`.
- **Endpoint show page:** the read-only documentation (title / description per locale) uses the UiKit `locale_tabs`
  partial directly (one panel per locale, rendered server-side); `api-endpoint-doc.js` edits the open panel's locale
  and follows `nowo-ui:tabs:change`.
- **Delete buttons** (endpoint and environment edit pages) open a UiKit confirm `<dialog>` (`nowo-ui-confirm.js`)
  instead of an inline `onsubmit="return confirm(…)"` (strict CSP).
- `base.html.twig` loads the UiKit runtimes through UiKit's `scripts` partial.
- Endpoint translation titles are validated to 255 characters (the column length) instead of failing on flush.
- Requires `nowo-tech/form-kit-bundle ^2.13` and `nowo-tech/ui-kit-bundle ^2.5.1` (UiKit 1.x is no longer supported).

### Removed

- `api-form-locale-tabs.js` (and its TypeScript source / build entry) and the `.as-locale-tab*` / `.as-doc-locale`
  styles.

[1.5.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.5.0

## [1.4.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-141).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS (and `api-studio-theme.css`) wins equal-specificity conflicts with kit rules; to keep the 1.x cascade, override the `nowo_ui_styles` block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

[1.4.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-10-13

### Added

- SOAP raw-envelope mode: when the endpoint body is a complete SOAP envelope (XML starting with `<` that contains an `Envelope` element), it is POSTed as-is (after `{{variable}}` substitution, as for REST bodies) to the service URL without its `?wsdl` / `?singleWsdl` query, through the same guarded HTTP client as JSON-parameter calls: service auth, default/endpoint/console headers, `SOAPAction` (SOAP 1.1) or `action=` in `Content-Type` (SOAP 1.2, detected from the envelope namespace), SSRF/allowlist checks, timeout and `execution_max_redirects`. No WSDL download and no ext-soap needed. A SOAP `Fault` (typically HTTP 500) or a non-2xx status fails the execution with `SOAP Fault (<code>): <reason>` while keeping the response for display; history redaction is the same as REST. Bodies that are not an envelope keep the existing JSON-parameters mode (WSDL + ext-soap). See [USAGE.md — SOAP services](USAGE.md#soap-services).

### Changed

- SOAP JSON-parameters mode: the endpoint **SOAP action** may now be a full SOAPAction URI (`http://tempuri.org/Svc/GetThing`, `urn:svc#GetThing`); the operation called is its last segment. Bare operation names behave as before.
- Demo seed (`nowo:api-studio:seed-demo`): re-seeding now adds reference environment variables introduced by newer seeds to existing environments (existing keys are never overwritten).

### Fixed

- Demo seed: the Catastro SOAP examples now work against the real service. They are raw SOAP envelopes with the document/literal parts and full SOAPAction URIs the ASMX service expects (the previous bodies wrapped the parameters in an operation element, used a bare SOAPAction and were read as JSON, so the call was sent without parameters). `Consulta_CPMRC` now queries coordinates by cadastral reference, the new `Consulta_RCCOOR` endpoint queries the reference at coordinates, and `Consulta_DNPRC` moved to a new *Catastro — OVCCallejero (SOAP)* service (`{{catastro_callejero_wsdl}}`), where the operation lives. Existing demo data is not rewritten: run `nowo:api-studio:seed-demo --fresh` to get the corrected examples.
- Demo (Symfony 8): the PHP image now installs ext-soap, so JSON-parameter SOAP calls work in the demo.
- SOAP: a 3xx answer from the SOAP endpoint that is not followed (`execution_max_redirects` is `0` by default, or exhausted) now fails the execution (`HTTP 3xx … not followed`) instead of handing an empty body to ext-soap, which recorded a successful call with a `null` result.
- SOAP: executions now send the HTTP headers REST executions send — service auth (`basic`/`bearer` `Authorization`, `api_key` header), service default headers, endpoint headers and console headers (console headers now replace the defaults as for REST) — with the SOAP call and, restricted to the WSDL's origin, with the WSDL/XSD downloads, so services and WSDLs behind auth work. `Content-Type` and `SOAPAction` stay owned by the SOAP transport: a header with one of those names is dropped instead of overriding them, and the endpoint content type (default `application/json`) is no longer recorded for SOAP executions. History redaction is unchanged and identical to REST (`HistorySanitizer`). See [USAGE.md — SOAP services](USAGE.md#soap-services).

### Security

- Regression coverage for SOAP redirects: a redirect of the SOAP call (`<soap:address location>`) to metadata, RFC1918 or loopback addresses is blocked by `NoPrivateNetworkHttpClient` before the hop is requested, a public redirect is followed only when `execution_max_redirects` allows it (with `SOAPAction` preserved), and the WSDL redirect tests now exercise the client's redirect handling.

[1.4.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.4.0

## [1.3.1] - 2026-10-13

### Fixed

- `/workspaces/new` no longer returns 500: `workspace/form.html.twig` reads `workspace.id` only when a workspace is passed (strict Twig variables).
- `ApiEnvironmentVariableFormType` and `ApiEndpointTranslationFormType` now live in their own PSR-4 files (same FQCNs), so the `Form\` resource loader registers them as `form.type` services and Form Kit injects their `FormOptionsMerger`; `environments/new`, environment edit, `endpoints/new` and endpoint edit no longer return 500. A unit test enforces one class-like per source file.
- `services/new` (and any page rendered while an unsaved service or endpoint sits in a loaded collection) no longer returns 500: the sidebar navigation tree skips entities without an id instead of generating `nowo_api_studio_service_show` / `nowo_api_studio_endpoint_show` with an empty id.
- Added a functional test kernel (`tests/Functional`) that renders every Api Studio GET page against a seeded SQLite database with strict Twig variables.

[1.3.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-10-13

### Security

- SOAP executions no longer bypass the SSRF guard: the WSDL (and every WSDL/XSD it imports) is downloaded through the guarded HTTP client (`NoPrivateNetworkHttpClient`, URL validator + `execution_url_allowlist` on each document and on the final URL after redirects, `execution_max_redirects`) and handed to ext-soap as a local temporary file; the SOAP call itself goes through the same client from `GuardedSoapClient::__doRequest()`, so the `<soap:address location>` is checked against the SSRF validator and allowlist (metadata/loopback/private endpoints are rejected), DNS rebinding is re-checked and `ui.request_timeout_seconds` applies as `timeout` + `max_duration`. WSDL documents with a DTD are rejected.
- Request history now also masks the values of secret environment variables (raw, URL-encoded and JSON-escaped, 4+ characters) in every stored request header and in the stored request body, not only in the URL; values sent in the execute payload for a secret variable are masked too.

[1.3.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-10-13

### Fixed

- **`environments` config is now used:** workspaces created from the UI are seeded with the configured environments (default `dev`, `test`, `prod`; the first one is the default). The key was documented but previously ignored.

### Changed

- **Requirements:** Symfony floor raised from `^7.0` to `^7.4` (still `|| ^8.0`), matching what CI installs; the `7.0` CI matrix cell was removed.

### Security

- **Request history redaction:** the stored request URL is now sanitized — values of query parameters with sensitive names (`token`, `api_key`, `access_token`, `password`, `secret`, `signature`, `key`, …), the service's API-key header name, userinfo passwords and the values of secret environment variables are replaced with `[REDACTED]`. Request headers also redact the service's custom API-key header (previously only well-known names were masked).

[1.2.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.2.0

## [1.1.0] - 2026-10-13

### Security

- `enabled: false` no longer leaves the UI and the outbound executor reachable without access checks: every `nowo_api_studio_*` route now answers 404 and the access subscriber is always registered.
- SSRF hardening: outbound REST/GraphQL calls go through Symfony `NoPrivateNetworkHttpClient` (re-checks the connected IP, defeating DNS rebinding); redirects are no longer followed by default (new `execution_max_redirects`, default `0`); the pre-flight check now blocks all `IpUtils::PRIVATE_SUBNETS` plus multicast (0.0.0.0/8, 100.64.0.0/10, fc00::/7, IPv4-mapped IPv6, `::`, …), checks every A/AAAA record and fails closed when the host does not resolve.
- `execution_url_allowlist` plain entries now match the parsed host exactly (or as a `.example.com` / `*.example.com` suffix; URL entries also pin scheme, port and path prefix) instead of a substring of the whole URL (`api.example.com` no longer matches `api.example.com.evil.net` or `?x=api.example.com`).
- `security.access_checker` is now enforced: the configured `ApiStudioAccessCheckerInterface::canAccess()` is called with the current user on every Api Studio route (anonymous users are denied unless `allow_unauthenticated: true`).
- Locale switch no longer redirects to arbitrary `Referer` URLs (open redirect); only same-host or local paths, otherwise the dashboard.
- Execute endpoint rejects (`400`) an `environment_id` that does not belong to the endpoint's workspace, so another workspace's secrets can no longer be resolved into a request.

[1.1.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.1.0

## [1.0.23] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to the minimum supported PHP so the committed lock stays installable there; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #53).
- `nowo-tech/ui-kit-bundle` 1.9.1 → 1.11.0 (development lock; #54).
- `nowo-tech/form-kit-bundle` 2.6.0 → 2.7.1 (development lock; #55).
- `vite` 8.3.2 → 8.3.4 (frontend tooling; #56).

[1.0.23]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.23

## [1.0.22] - 2026-10-09

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Fixed

- **Tests (Doctrine):** the test entity manager enables native lazy objects only on PHP 8.4+; `symfony/var-exporter`
  is now required as `^7.4 || ^8.0` (LazyGhost support), keeping the PHP 8.2 Docker test environment installable.

### Dependencies

- **Runtime:** `doctrine/orm` 3.7.4 (constraint `^3.7`), `nowo-tech/form-kit-bundle` 2.6.0, `nowo-tech/ui-kit-bundle` 1.9.1,
  `symfony/*` 7.4.20 in the bundle lockfile.
- **Dev:** `phpstan/phpstan` 2.3.1 (+ `phpstan-symfony` 2.1.0, `phpstan-phpunit` 2.1.1), `rector/rector` 2.7.0,
  `friendsofphp/php-cs-fixer` 3.95.27, `igor-php/igor-php` 0.10.1, `phpunit/phpunit` 11.5.57, `vite` 8.3.2.
- **Demo (symfony8):** Symfony 8.1.8, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.0.22]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.22

## [1.0.21] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **Secrets:** `SecretVariableEncryptionListener` writes the encrypted value through `PreUpdateEventArgs::setNewValue()`,
  encrypts variables marked as secret without a value change (or after `persist()`), and restores the plaintext on the
  managed entity after insert/update, so later requests on the same worker never send ciphertext as a variable value.
- **FrankenPHP worker (no kernel reset):** sidebar tree moved from the memoized Twig global `nowo_api_studio_nav_tree`
  to the `api_studio_nav_tree()` function (fresh on every render, no query on host pages); a closed Api Studio entity
  manager is reset at the start of the next Api Studio request; executed request history entries are detached after
  flush; outbound HTTP uses `max_duration` equal to `request_timeout_seconds`; `PayloadBodyHelper` no longer stores
  the last XML error in a property. See `docs/FRANKENPHP-WORKER-AUDIT.md`.

[1.0.21]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.21

## [1.0.20] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Makefile:** detect Docker Compose V2 (REQ-MAKE-010).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.0.20]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.20

## [1.0.19] - 2026-08-20

### Documentation

- **SECURITY.md / REQ-SEC-004:** re-audit **Pass (good)** — prod Flex `execution_url_allowlist_required`, HistorySanitizer on write; residual = host history retention.

## [1.0.18] - 2026-08-20

### Security

- **Flex recipe:** ship `security_nowo_api_studio.yaml` access rules for `/api-studio` (REQ-SEC-004). Prefer **`^1.0.18`** so Flex installs the file.

### Tests

- **SSRF regression:** executor fails closed on private URLs and allowlist misses without calling HttpClient; add `172.16.x` case to `ExecutionUrlValidatorTest`.

## [1.0.17] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

[1.0.17]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.17

## [1.0.16] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.0.16]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.16

## [1.0.15] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `api_studio` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added

- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.
- New Composer requirements: `nowo-tech/form-kit-bundle` `^2.0`, `twig/extra-bundle` `^3.12`, `twig/string-extra` `^3.12`.

[1.0.15]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.15

## [1.0.14] - 2026-08-04

### Added

- Hard dependency on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`) (REQ-UI-001-kit).
- `ApiStudioExtension` seeds `nowo_ui_kit.css_framework` / `icon_set` from `ui.css_framework` when the host has not configured UiKit.

### Changed

- Admin `base.html.twig` loads `nowo-ui.css`; workspace list uses UiKit macros (`ui.btn`, `ui.table`, `ui.empty`).
- Demo requires UiKit explicitly, registers the bundle, and uses the UiKit locale switcher on the host navbar (outside Bootstrap `.navbar-nav`).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.
- New Composer requirement: `nowo-tech/ui-kit-bundle` `^1.4`.

[1.0.14]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.14

## [1.0.13] - 2026-07-30

### Changed

- Twig pages extend `@NowoApiStudioBundle/base.html.twig`, which stacks package CSS/JS with `{{ parent() }}` on top of `ui.layout_template` (REQ-UI-001).
- README documentation section order and links refreshed.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.13]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.13

## [1.0.12] - 2026-07-29

### Changed

- Makefiles probe Compose V2 via absolute `docker` binary (`command -v`) before falling back to `docker-compose` (REQ-MAKE-010).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.12]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.12

## [1.0.11] - 2026-07-29

### Changed

- Root and demo Makefiles prefer Compose V2 (`docker compose`) with V1 fallback (REQ-MAKE-010).
- Demo Makefile resolves `docker` via `command -v` so the local `demo/symfony8/docker/` directory cannot shadow the CLI when `.` is on `PATH`.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.11]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.11

## [1.0.10] - 2026-07-28

### Fixed

- Demo Makefile / `demo-smoke` CI: use Compose V2 (`docker compose`) when `docker-compose` is absent.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.10]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.10

## [1.0.9] - 2026-07-28

### Fixed

- Makefile `update-deps` includes are optional outside the bundles monorepo so `make demo-smoke` works on GitHub Actions.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.9]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.9

## [1.0.8] - 2026-07-28

### Added

- **`HistorySanitizer`**: redacts sensitive headers/bodies before request history persist (REQ-OBS-001).
- **`make demo-smoke`** + `.github/workflows/demo-smoke.yml` (REQ-TEST-011).
- `RequestExecutor` structured logging (start/finish/failure, host only) via `LoggerInterface` (REQ-OBS-001).

### Changed

- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI (REQ-SF-005).
- Long docs: Table of contents (REQ-DOCS-005).
- `check-open-prs` resolves `owner/repo` from `origin` via `-R` when `gh` cannot map the SSH remote.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.8]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.8

## [1.0.7] - 2026-07-27

### Fixed

- Twig partials use translation domain `NowoApiStudioBundle` (REQ-I18N-003 residual).
- Shell CSS/JS moved into `stylesheets` / `javascripts` blocks for `{{ parent() }}` stacking (REQ-UI-001).
- Demo Makefile: `setup` / `verify` targets (REQ-MAKE-003).
- Root `release-check` runs `ensure-up` first (REQ-MAKE-002).
- README documents PHP coverage exclusions (REQ-TEST-003 honesty).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.7]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.7

## [1.0.6] - 2026-07-27

### Added

- At-rest sodium encryption for environment variables marked `secret` (`secrets.encrypt`, default `true`).
- `execution_url_allowlist_required` compile-time guard for production hardening.
- REQ-SEC-004 Pass (conditional): Medium residual risk documented in `docs/SECURITY.md`.
- Unit tests: `SecretValueCipherTest`, `ApiStudioSecurityPassTest` (allowlist-required).
- English `@packageDocumentation` / JSDoc on remaining frontend TS entrypoints (REQ-ASSETS-002).

### Changed

- Flex recipe documents `secrets` and `execution_url_allowlist_required` (defaults remain BC-safe).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.6]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.6

## [1.0.5] - 2026-07-27

### Added

- `ui.layout_template` / `ui.css_framework` and Twig globals for host layout embedding (REQ-UI-001).
- `security.allow_unauthenticated` (default `false`) with compile-time SecurityBundle guard (REQ-UI-002).
- `make check-open-prs` in `release-check` (REQ-REL-003); `.scripts/check-open-prs.sh`.
- Unit tests: `TwigPathsPassTest`, `RequestExecutorTimeoutTest` (REQ-TWIG-001 / REQ-RUNTIME-001).

### Fixed

- Asset package `base_path` aligned to `/bundles/apistudio` (matches `assets:install`; REQ-ASSETS-004).
- Form `translation_domain` set to `NowoApiStudioBundle` (REQ-I18N-003).

### Changed

- Demo: `allow_unauthenticated: true`, documented `.env.example`, DNS comment, Makefile aliases; `make up` without image rebuild.
- GitHub About: website + topics (REQ-DOCS-018).
- Pages extend `nowo_api_studio_layout_template`; child `javascripts` blocks call `{{ parent() }}`.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.5]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.5

## [1.0.4] - 2026-07-27

### Added

- `docs/DEMO-FRANKENPHP.md` — FrankenPHP classic/worker docs, timeout hierarchy for the request console (`ui.request_timeout_seconds`).
- Makefile: `validate-translations` (YAML lint) included in `release-check`; `down-dev`.
- Frontend toolchain: `packageManager` **pnpm**, `vite.config.ts`, `pnpm-lock.yaml`; root Docker image includes Node/pnpm for `make assets`.
- GitHub: Dependabot, Copilot instructions, PR title lint, stale issues workflow; `.scrutinizer.yml`.
- `docs/USAGE.md`: Twig override paths (`templates/bundles/NowoApiStudioBundle/`) and translation domain `NowoApiStudioBundle`.

### Changed

- Demo default `PORT` aligned to **8023** (`.env.example` / Compose).
- README: Symfony badge `7.4 | 8.0 | 8.1+`, canonical `## Documentation` order, explicit PHP / TS/JS / Python coverage lines, FrankenPHP CTA.
- Issue templates and `CODEOWNERS` renamed from leftover WorkflowBundle references to ApiStudioBundle.
- Assets build via `pnpm install` + `pnpm run build` inside Docker (replaces npm / `package-lock.json`).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.4]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.4

## [1.0.3] - 2026-07-23

### Added

- Maintainer tooling: `nowo-tech/phpstan-frankenphp` (`require-dev`) with `ruleset-classic` + `ruleset-worker` in `phpstan.neon.dist` (REQ-CS-005).
- README FrankenPHP Friendly Worker Mode banner (`docs/images/frankenphp-friendly.png`) and canonical worker-friendly claim (REQ-DOCS-017).

### Changed

- PHPStan: empty `ignoreErrors`, `treatPhpDocTypesAsCertain: true`, and PHPDoc local `@var` annotations so defensive runtime checks remain valid.
- `SchemaSyncService` uses DBAL `introspectSequences()` instead of deprecated `listSequences()`.
- PHP-CS-Fixer: `fully_qualified_strict_types.import_symbols` enabled.

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.3]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.3

## [1.0.2] - 2026-07-22

### Changed

- Symfony 8 demo: extract Docker entrypoint to `demo/symfony8/docker/entrypoint.sh` and support `FRANKENPHP_MODE=worker|classic` (default `worker`) via `.env` / Compose.

### Fixed

- Demo entrypoint always applies the selected Caddyfile under `/etc/frankenphp/Caddyfile` (the path FrankenPHP actually loads).

### Compatibility

- Unchanged: PHP `>=8.2 <8.6`, Symfony `^7.0 || ^8.0`.

[1.0.2]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.2

## [1.0.1] - 2026-07-16

### Changed

- Minimum supported Symfony version is **7.0** (`^7.0 || ^8.0`); PHP remains `>=8.2 <8.6`.
- CI matrix aligned to PHP 8.2+ × Symfony 7.0 / 7.4 / 8.0 / 8.1 (removed PHP 8.1 and Symfony 6.4).

### Fixed

- CI install for Symfony 8: keep `symfony/browser-kit` in `require-dev`, and run a full `composer update -W` so `doctrine/doctrine-bundle` can resolve to `^3` on Symfony 8 / PHP 8.4+.

### Compatibility

- PHP `>=8.2 <8.6`
- Symfony `^7.0 || ^8.0`
- Doctrine ORM `^2.15 || ^3.0` / DoctrineBundle `^2.10 || ^3.0`

[1.0.1]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.1

## [1.0.0] - 2026-07-16

First stable release of **Api Studio Bundle** — manage, document, and test REST, SOAP, and GraphQL APIs from a built-in Symfony dashboard.

### Added

- Workspaces, services, environments/variables, and endpoints (REST, SOAP, GraphQL).
- Multilingual endpoint documentation (title, description, notes) and request/response examples.
- In-browser request console with execution history.
- Browser pre/post-request scripts (`pm.environment`, `pm.response`, `pm.test`) with optional persistence to environment variables.
- Import/export: OpenAPI 3 / Swagger 2, Postman collections, environment variables (JSON/YAML/`.env`).
- Schema sync and demo seed CLI (`nowo:api-studio:sync-schema`, `nowo:api-studio:seed-demo`).
- Access control via `security.access_roles` (preferred) or legacy `ui.required_roles`; optional custom `security.access_checker`.
- SSRF protection for outbound URLs (`ExecutionUrlValidator`) and optional `execution_url_allowlist`.
- Configurable `table_prefix`, UI path, locales, and request timeout.
- Maintainer tooling: Spec Kit baseline, release/CI docs, REQ-GIT-001 (no Cursor co-author trailers).

### Fixed

- Preserve libxml parse errors in `PayloadBodyHelper` so invalid XML messages are not lost after restoring libxml mode.
- Suppress PHP warnings for invalid `execution_url_allowlist` regex patterns while still logging them.
- Align unit tests with `VariableSyntax` empty-key validation.
- PHPStan level-8 clean-up (generics/array shapes) for release readiness.
- Limit Rector paths to `src`/`tests` so `make release-check` does not scan demo vendor.

### Compatibility

- PHP `>=8.2 <8.6`
- Symfony `^7.4 || ^8.0` (relaxed to `^7.0 || ^8.0` in 1.0.1)
- Doctrine ORM `^2.15 || ^3.0` / DoctrineBundle `^2.10 || ^3.0`

See [Upgrading](UPGRADING.md) and [Release](RELEASE.md).

[1.0.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.0
