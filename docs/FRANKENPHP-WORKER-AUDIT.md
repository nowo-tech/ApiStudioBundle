# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/api-studio-bundle` (`symfony-bundle`) |
| Audited revision | `v1.0.20` / `4970410` |
| Audit date | 2026-09-23 |
| Method | Manual review of every PHP file under `src/` (controllers, services, import/export, security, Doctrine listeners, Twig extension, subscribers, forms, commands, DI extension, compiler passes, `Resources/config/services.yaml`) |
| Re-audit (2026-11-12, REQ-RUNTIME-002) | 1.12.1: the bundle-owned manager (`entity_manager`, 1.12.0) is cleared at the request, Messenger and console boundaries and recreated when closed (`EntityManagerBoundarySubscriber`); the tenant context and route scope listener are reviewed; one-kernel worker test with two tenants and four users without the resetter (see [Boundaries and worker test](#boundaries-and-worker-test-1121)) |
| Remediation (2026-09-23) | W-01/W-04 nav tree moved to the `api_studio_nav_tree()` Twig function; W-02 secrets listener keeps plaintext in memory and uses `setNewValue()`, closed EntityManager reset on next Api Studio request, history detached after flush; W-03 `max_duration` added; W-05 XML error returned instead of stored. Regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — no bundle-owned per-request state; Doctrine identity-map clearing between requests remains the host application's responsibility (see W-02) |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Boundaries and worker test (1.12.1)](#boundaries-and-worker-test-1121)
- [Findings](#findings)
  - [W-01 — Sidebar navigation tree is cached by Twig as a global (Medium)](#w-01--sidebar-navigation-tree-is-cached-by-twig-as-a-global-medium)
  - [W-02 — Doctrine usage relies on the EntityManager reset (Medium)](#w-02--doctrine-usage-relies-on-the-entitymanager-reset-medium)
  - [W-03 — Outbound execution can pin a worker thread for a long time (Low)](#w-03--outbound-execution-can-pin-a-worker-thread-for-a-long-time-low)
  - [W-04 — The navigation global queries the database on every request that renders Twig (Low)](#w-04--the-navigation-global-queries-the-database-on-every-request-that-renders-twig-low)
  - [W-05 — `PayloadBodyHelper` keeps the last XML error in a property (Info)](#w-05--payloadbodyhelper-keeps-the-last-xml-error-in-a-property-info)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | All services use `readonly` constructor config/collaborators; `PayloadBodyHelper` no longer has properties (W-05) |
| Static properties / `static` locals | ✅ | Only pure static helpers (`VariableSyntax`, `SlugHelper`, enum `values()`); no static properties or `static` locals |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `ApiStudioTenantContext` and `ApiStudioRouteScopeListener` implement it (both `reset()` tested) but do not rely on it: the override is restored in `finally`, the router context on `kernel.finish_request`. The bundle-owned manager is cleared at every boundary by the bundle (1.12.1); the only request-dependent Twig value is a function (W-01) |
| Request / user / locale captured in services | ✅ | `LocaleManager` reads the session through `RequestStack` per call; access checks call `AuthorizationCheckerInterface` per request |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used. `libxml_use_internal_errors()` is restored after each parse (`src/Service/PayloadBodyHelper.php:186-203`) |
| Doctrine / EntityManager | ✅ | Managed secret variables always hold plaintext; history detached after flush. With `entity_manager` set, the bundle-owned manager is cleared at every request / message / command boundary and recreated when closed (`EntityManagerBoundarySubscriber`, 1.12.1); without it, the identity map is the host's (W-02, REQ-RUNTIME-002 rule 7) |
| Output, headers, `exit`, shutdown functions | ✅ | None; all output goes through `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | `SoapClient` is created per call (`src/Service/RequestExecutor.php:195`); uploads are read through `UploadedFile`; no handles kept in properties |
| Memory growth across requests | ✅ | No bundle-level caches; executed `ApiRequestHistory` rows are detached after flush. Other loaded entities stay in the host-owned identity map until the host clears it (W-02) |
| Blocking I/O and timeouts | ✅ | HttpClient uses `timeout` + `max_duration` (SOAP included); resolver timeout remains host configuration (W-03, accepted) |
| Third-party static state | ✅ | Symfony Yaml, HttpClient, ext-soap, ext-sodium and DOM are used per call; no global settings changed |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Twig\ApiStudioExtension` (`GlobalsInterface`) | yes | none; globals are static config only, nav tree is the `api_studio_nav_tree()` function | ✅ | ✅ |
| `Service\StudioNavigationProvider` | yes | none (queries on every call) | ✅ | ✅ |
| `Service\RequestExecutor` | yes | none (`readonly` HTTP client, resolver, validator, timeout, logger) | ✅ | ✅ |
| `Security\ExecutionUrlValidator` | yes | none (`readonly` allowlist) | ✅ (W-03 accepted) | ✅ (W-03 accepted) |
| `Security\SecretValueCipher` | yes | none (`readonly` key derived from config at construction) | ✅ | ✅ |
| `Doctrine\SecretVariableEncryptionListener`, `Doctrine\TablePrefixSubscriber` | yes | none; mutate entities/change sets, not themselves | ✅ | ✅ |
| `EventListener\EntityManagerRecoverySubscriber` (`final readonly`) | yes | none (`ManagerRegistry` only) | ✅ | ✅ |
| `EventListener\EntityManagerBoundarySubscriber` (1.12.1) | yes | console depth counter and in-flight message / request flags (no request data) | ✅ | ✅ |
| `Tenancy\ApiStudioTenantContext` | yes | `$override`, set only inside `runAs()` and restored in `finally`; the request tenant lives on the main request's attributes | ✅ | ✅ |
| `Routing\ApiStudioRouteScopeListener` (`final readonly`) | yes | none; sets router-context parameters per request and restores them on `kernel.finish_request` | ✅ | ✅ |
| `Service\LocaleManager`, `EventListener\LocaleSubscriber`, `EventSubscriber\ApiStudioAccessSubscriber` (`final readonly`), `Security\ConfigurableApiStudioAccessChecker` (`final readonly`) | yes | none | ✅ | ✅ |
| `Service\VariableResolver`, `Service\HistorySanitizer`, `Service\EnvironmentContextBuilder`, `Service\ImportExport\DocumentParser`, `SlugHelper` | yes | none | ✅ | ✅ |
| `Service\PayloadBodyHelper` | yes | none | ✅ | ✅ |
| `OpenApiImporter`, `OpenApiExporter`, `PostmanCollectionImporter`, `EnvironmentVariableImporter`, `EnvironmentVariableExporter`, `SchemaSyncService`, `DemoSeedService` | yes | none (EntityManager / parser only) | ✅ | ✅ (host clears identity map, W-02) |
| 10 controllers (`AbstractController`, constructor-injected repositories/services) | yes | none | ✅ | ✅ (host clears identity map, W-02) |
| 9 Doctrine repositories (`ServiceEntityRepository`, 20 lines each) | yes | none | ✅ | ✅ (host clears identity map, W-02) |
| 6 form types (`ApiEndpointFormType`, `ApiEnvironmentFormType`, `ApiServiceFormType`, `ApiWorkspaceFormType`, `ImportFileFormType`, `JsonMapType`) | yes | none (no constructor, stateless transformers) | ✅ | ✅ |
| `Command\SyncSchemaCommand`, `Command\SeedDemoCommand` | yes | none (CLI only) | ✅ | ✅ |

Entities, enums and models (`ApiExecutionResult`, `ImportResult`) are created per request and never stored in service properties.

## Boundaries and worker test (1.12.1)

Scenario B here means: no services resetter (a host that removes, replaces or bypasses `services_resetter` or drives
`http_kernel` directly, `messenger:consume --no-reset`, long-running commands). With the stock Symfony kernel the
resetter still runs before every request, even with `FRANKENPHP_RESET_KERNEL=false`; DoctrineBundle also clears every
manager after each Messenger message.

With `entity_manager` set to a manager other than the host's default one, the manager is **bundle-owned**
(REQ-RUNTIME-002 rule 4). `EventListener\EntityManagerBoundarySubscriber` recreates it through the registry when a
failed write closed it, and otherwise clears it, on:

- main `kernel.request` (priority 4096) and `kernel.terminate` (priority -4096); sub-requests share the main request;
- Messenger `WorkerMessageReceivedEvent` (4096) and `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (-4096);
- `console.command` (4096) and `console.terminate` (-4096) of the outermost command only, and only outside a message
  and a main request: a command run in-process (`Application::doRun()`) from another command, a message handler or a
  controller keeps the managed entities and pending changes of the unit that runs it (console depth counter, in-flight
  message flag, in-flight request flag).

An open manager is never replaced, so the pending changes of a running unit are never discarded. The manager arrives
as a lazy iterator of an `IGNORE_ON_UNINITIALIZED` reference set by `ApiStudioEntityManagerPass`, so a boundary never
builds it. Without `entity_manager` (or with it naming the host's default manager) the iterator is empty and the bundle
never clears the host's manager: the host clears it at every boundary (REQ-RUNTIME-002 rule 7), or keeps
`services_resetter`.

**Worker test:** `tests/Functional/Worker/WorkerStateTest.php` boots one `WorkerTestKernel` (non-debug, a DDD host with
the dedicated `api_studio` manager, tenancy on with one tenant per `{org}`), drives `http_kernel` directly and consumes
with a Messenger `Worker` that has no `ResetServicesListener`. Two tenants (acme: alice, bob; globex: carol, dave)
alternate over **500 iterations**: each user lists their tenant's workspaces (2,000 requests; another worker renames the
workspace through DBAL before each request), one message reads and flushes a workspace and one renames the other
tenant's in memory and fails before its flush (1,000 messages). It asserts that each page shows its tenant's current
name and nothing of the other tenant, that each message reads the current row, that the failed message's change is
never written by a later flush, that the Api Studio manager holds no entity after any unit of work, that the host's
manager keeps its managed entity after the requests, and that memory after a cycle collection grows less than **4 MiB**
after a 50-iteration warm-up. The same class covers the closed-manager recreation, a nested `doRun()` command and the
services resetter; `ApiStudioEntityManagerPassTest` covers the wiring (bundle-owned manager only). Without the
subscriber the worker test fails at its first iteration (stale workspace name).

**Static checks:** `composer igor` and the PHPStan FrankenPHP worker ruleset pass.

## Findings

### W-01 — Sidebar navigation tree is cached by Twig as a global (Medium)

- **Where:** `src/Twig/ApiStudioExtension.php:40-48` returns `nowo_api_studio_nav_tree => $this->navigationProvider->buildTree()` from `getGlobals()`; `src/Service/StudioNavigationProvider.php:22-84` queries all workspaces, services, endpoints and request examples and generates URLs. Twig caches the result in `Environment::$resolvedGlobals` once the extension set is initialized (`vendor/twig/twig/src/Environment.php:904-915`, twig/twig v3.29.0). TwigBundle clears it only through the `kernel.reset` tag `resetGlobals` on the `twig` service (`vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php:51-52`, symfony/twig-bundle v7.4.19).
- **Worker impact:** under **A** the globals are recomputed after every reset, so this is fine. Under **B** the tree built during the first render of the worker is reused forever: new workspaces, services, endpoints and examples never appear in the sidebar, deleted ones keep dead links, and the URLs keep the base path of the request that built them. The tree holds only ids, names and URLs (no entities) and is the same for every user, so it is stale data, not a cross-user leak.
- **Recommendation:** keep `services_resetter` enabled. Better: expose the tree through a Twig function (for example `api_studio_nav_tree()`) instead of a global, so it is built at render time and only on Api Studio pages.
- **Status:** Resolved — the global was removed from `src/Twig/ApiStudioExtension.php`; the new `api_studio_nav_tree()` Twig function builds the tree at call time and `src/Resources/views/_sidebar_tree.html.twig` uses it. The global was not documented public API; the change is noted in `docs/UPGRADING.md`. Test: `tests/Unit/Twig/ApiStudioExtensionTest.php` renders twice on the same `Environment` without `resetGlobals()` and sees the new workspace.

### W-02 — Doctrine usage relies on the EntityManager reset (Medium)

- **Where:** every write controller persists and flushes on the shared EntityManager (22 `flush()` calls under `src/`, for example `src/Controller/ApiExecuteController.php:137-138`, which stores one `ApiRequestHistory` per execution). `src/Doctrine/SecretVariableEncryptionListener.php:43-55` decrypts secret variable values into the managed entity on `postLoad`, and `:60-72` replaces the in-memory value with ciphertext on `prePersist` / `preUpdate`. `src/Entity/ApiEnvironment.php:144-152` (`getVariableMap()`) reads those in-memory values, which `RequestExecutor` then uses.
- **Worker impact:** under **A**, DoctrineBundle clears or recreates the EntityManager between requests, so this is safe. Under **B**:
  - Every loaded entity and every `ApiRequestHistory` stays in the identity map, so memory grows with usage.
  - Edits made by another worker thread are not seen, because managed entities are not refreshed.
  - Decrypted secret values stay in worker memory across requests.
  - After a secret variable is saved, the managed entity keeps the ciphertext. Later requests in the same thread then send the ciphertext as the variable value (for example in an `Authorization` header).
  - A flush failure (such as a unique constraint violation) closes the EntityManager, and every later Api Studio request on that thread fails.
- **Recommendation:** keep `services_resetter` enabled (scenario A). A host that disables it must call `ManagerRegistry::resetManager()` / `EntityManager::clear()` between requests.
- **Status:** Resolved (bundle-owned parts) / Accepted (host-owned parts):
  - Ciphertext on the managed entity — resolved in `src/Doctrine/SecretVariableEncryptionListener.php`: `preUpdate` now writes the ciphertext with `PreUpdateEventArgs::setNewValue('value', …)` (the entity keeps plaintext), `onFlush` encrypts variables marked secret without a value change or after `persist()`, and `postPersist` / `postUpdate` restore the plaintext on the entity. Note: on doctrine/orm 3.7 (installed) the UnitOfWork recomputes the change set after `preUpdate`, so the previous `setValue()` in `preUpdate` did store ciphertext, but left it on the managed entity; the plaintext-at-rest case was toggling `secret` on without changing the value. Tests: `tests/Unit/Doctrine/SecretVariableEncryptionListenerTest.php` (real SQLite EntityManager; consecutive flushes without `clear()`).
  - Closed-EntityManager cascade — resolved: `src/EventListener/EntityManagerRecoverySubscriber.php` resets the manager of the Api Studio entities on the next main Api Studio request (priority 31, after routing, before the firewall) when it is closed. Since 1.12.1 a bundle-owned manager (`entity_manager`) is also cleared and recovered at every boundary (see [Boundaries and worker test](#boundaries-and-worker-test-1121)); the host's manager is never cleared. Test: `tests/Unit/EventListener/EntityManagerRecoverySubscriberTest.php`.
  - Identity-map growth from executions — resolved: `src/Controller/ApiExecuteController.php` detaches each `ApiRequestHistory` after flush.
  - Stale entities edited by other worker threads and decrypted secrets kept in memory — accepted: they live in the host-owned EntityManager. Clearing the identity map between requests (`services_resetter` or `EntityManager::clear()` in the host loop) remains the application's responsibility under scenario B. No Api Studio read is a security decision that needs a forced refresh (access is decided by roles, not DB rows).

### W-03 — Outbound execution can pin a worker thread for a long time (Low)

- **Where:** `src/Service/RequestExecutor.php:128-136` passes `timeout => request_timeout_seconds` to HttpClient. That is an idle timeout, and no `max_duration` is set. `:195-199` builds `SoapClient` with only `connection_timeout`, so reads fall back to the `default_socket_timeout` ini value. `src/Security/ExecutionUrlValidator.php:76` calls `gethostbyname()`, which uses the system resolver timeout. `request_timeout_seconds` defaults to 30 and accepts up to 300 (`src/DependencyInjection/Configuration.php:74-78`).
- **Worker impact:** a slow or trickling target can hold one of the limited worker threads for much longer than the configured value (for example a server that sends a byte every few seconds). No state leaks.
- **Recommendation:** add `max_duration` equal to the timeout for HttpClient, set `default_socket_timeout` (or a stream context) for SOAP, keep resolver `options timeout:1 attempts:2`, and cap waiting requests with FrankenPHP `max_wait_time`.
- **Status:** Resolved for HTTP/GraphQL — `src/Service/RequestExecutor.php` passes `max_duration` = `request_timeout_seconds` (test in `tests/Unit/Service/RequestExecutorTimeoutTest.php`). Resolved for SOAP (Unreleased): the WSDL download and SOAP calls go through the same HttpClient (`src/Soap/GuardedWsdlLoader.php`, `src/Soap/GuardedSoapClient.php`) with `timeout` + `max_duration`. Accepted for DNS: set resolver options in the host together with FrankenPHP `max_wait_time`.

### W-04 — The navigation global queries the database on every request that renders Twig (Low)

- **Where:** same code as W-01. Twig resolves globals for any template, so `buildTree()` runs on the first Twig render of each request, host application pages included, and lazily loads every workspace, service, endpoint and request example.
- **Worker impact:** under **A** this adds a full catalog query (with lazy-loading round trips) to every request that renders Twig anywhere in the application, including host pages that never show Api Studio, and it fills the identity map. If the Api Studio tables are missing or the connection fails, host templates fail too. No data leaks.
- **Recommendation:** same as W-01: move the tree to a Twig function or a controller-provided variable.
- **Status:** Resolved with W-01 — globals no longer query the database; the tree is built only by templates that call `api_studio_nav_tree()` (test asserts `getGlobals()` does not call the repository).

### W-05 — `PayloadBodyHelper` keeps the last XML error in a property (Info)

- **Where:** `src/Service/PayloadBodyHelper.php:28,188,204`.
- **Worker impact:** none in practice. The property is cleared at the start of every `loadXml()` and only read right after in the same call. The service is registered but not referenced by other PHP classes under `src/`.
- **Recommendation:** return the error from `loadXml()` instead of storing it, to keep the service strictly stateless.
- **Status:** Resolved — `src/Service/PayloadBodyHelper.php` returns the error through a by-reference argument; the class has no properties (test in `tests/Unit/Service/PayloadBodyHelperTest.php`).

No other worker findings. One observation outside worker scope: `SecretVariableEncryptionListener::preUpdate()` changes the entity with `setValue()` instead of `PreUpdateEventArgs::setNewValue()`. Doctrine does not pick up field changes made in `preUpdate`, so updated secret values may be written in plaintext. This should be verified with a test.

**Status:** Resolved — verified with a real SQLite EntityManager. On doctrine/orm 3.7 an edited value was still stored encrypted (the UnitOfWork recomputes the change set after `preUpdate`), but the managed entity kept the ciphertext, and marking an existing variable as secret without changing its value stored it in plaintext. Both are fixed (see W-02) and the listener now uses `setNewValue()` as documented by Doctrine.

## Usage recommendations in worker mode

- Keeping Symfony's `services_resetter` active is still recommended so the host EntityManager is cleared between requests; the bundle itself no longer depends on the Twig global reset. A host that removes or bypasses it must clear its own entity managers at every boundary (main request start or end, Messenger message, console command); otherwise the identity map serves stale rows across requests. Set `entity_manager` to a dedicated manager to have the bundle clear the Api Studio rows itself.
- Keep `request_timeout_seconds` low (the default is 30) and configure `execution_url_allowlist` so the studio cannot be used to reach slow or unexpected hosts.
- Api Studio is an internal tool. Prefer running it on an admin host or with few worker threads so long outbound calls cannot starve public traffic.
- A custom `security.access_checker` service or template overrides must not keep per-request state in service properties.
- If memory grows over time, set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) to recycle workers.
- Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` runs the demo with a `worker` block.

## Re-audit triggers

Re-run this audit when a change adds: a new Twig global or `GlobalsInterface` extension (request-dependent values must be Twig functions), properties to `RequestExecutor` / `ExecutionUrlValidator` / importers, a cache of workspaces or environments, new Doctrine listeners that buffer entities, a new outbound client (HTTP, SOAP, gRPC), or any use of `$_SERVER` / `$_ENV` / `ini_set` at runtime.
