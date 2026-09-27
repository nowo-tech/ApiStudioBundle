# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/api-studio-bundle` (`symfony-bundle`) |
| Audited revision | `v1.0.20` / `4970410` |
| Audit date | 2026-09-23 |
| Method | Manual review of every PHP file under `src/` (controllers, services, import/export, security, Doctrine listeners, Twig extension, subscribers, forms, commands, DI extension, compiler passes, `Resources/config/services.yaml`) |
| Remediation (2026-09-23) | W-01/W-04 nav tree moved to the `api_studio_nav_tree()` Twig function; W-02 secrets listener keeps plaintext in memory and uses `setNewValue()`, closed EntityManager reset on next Api Studio request, history detached after flush; W-03 `max_duration` added; W-05 XML error returned instead of stored. Regression tests simulate consecutive requests without `reset()` |
| **Verdict** | ✅ **Viable under scenario B** — no bundle-owned per-request state; Doctrine identity-map clearing between requests remains the host application's responsibility (see W-02) |

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
| `ResetInterface` / `kernel.reset` coverage | ✅ | The bundle needs none: the only request-dependent Twig value is now a function (W-01); a closed EntityManager is reset by the bundle itself (W-02) |
| Request / user / locale captured in services | ✅ | `LocaleManager` reads the session through `RequestStack` per call; access checks call `AuthorizationCheckerInterface` per request |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used. `libxml_use_internal_errors()` is restored after each parse (`src/Service/PayloadBodyHelper.php:186-203`) |
| Doctrine / EntityManager | ✅ | Managed secret variables always hold plaintext; closed EntityManager reset by `EntityManagerRecoverySubscriber`; history detached after flush. Identity-map staleness across worker threads is the host's responsibility (W-02) |
| Output, headers, `exit`, shutdown functions | ✅ | None; all output goes through `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | `SoapClient` is created per call (`src/Service/RequestExecutor.php:195`); uploads are read through `UploadedFile`; no handles kept in properties |
| Memory growth across requests | ✅ | No bundle-level caches; executed `ApiRequestHistory` rows are detached after flush. Other loaded entities stay in the host-owned identity map until the host clears it (W-02) |
| Blocking I/O and timeouts | ✅ | HttpClient uses `timeout` + `max_duration`; SOAP read timeout and resolver timeout remain host configuration (W-03, accepted) |
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
| `Service\LocaleManager`, `EventListener\LocaleSubscriber`, `EventSubscriber\ApiStudioAccessSubscriber` (`final readonly`), `Security\ConfigurableApiStudioAccessChecker` (`final readonly`) | yes | none | ✅ | ✅ |
| `Service\VariableResolver`, `Service\HistorySanitizer`, `Service\EnvironmentContextBuilder`, `Service\ImportExport\DocumentParser`, `SlugHelper` | yes | none | ✅ | ✅ |
| `Service\PayloadBodyHelper` | yes | none | ✅ | ✅ |
| `OpenApiImporter`, `OpenApiExporter`, `PostmanCollectionImporter`, `EnvironmentVariableImporter`, `EnvironmentVariableExporter`, `SchemaSyncService`, `DemoSeedService` | yes | none (EntityManager / parser only) | ✅ | ✅ (host clears identity map, W-02) |
| 10 controllers (`AbstractController`, constructor-injected repositories/services) | yes | none | ✅ | ✅ (host clears identity map, W-02) |
| 9 Doctrine repositories (`ServiceEntityRepository`, 20 lines each) | yes | none | ✅ | ✅ (host clears identity map, W-02) |
| 6 form types (`ApiEndpointFormType`, `ApiEnvironmentFormType`, `ApiServiceFormType`, `ApiWorkspaceFormType`, `ImportFileFormType`, `JsonMapType`) | yes | none (no constructor, stateless transformers) | ✅ | ✅ |
| `Command\SyncSchemaCommand`, `Command\SeedDemoCommand` | yes | none (CLI only) | ✅ | ✅ |

Entities, enums and models (`ApiExecutionResult`, `ImportResult`) are created per request and never stored in service properties.

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
  - Closed-EntityManager cascade — resolved: `src/EventListener/EntityManagerRecoverySubscriber.php` resets the manager of the Api Studio entities on the next main Api Studio request (priority 31, after routing, before the firewall) when it is closed. The bundle never clears an open manager. Test: `tests/Unit/EventListener/EntityManagerRecoverySubscriberTest.php`.
  - Identity-map growth from executions — resolved: `src/Controller/ApiExecuteController.php` detaches each `ApiRequestHistory` after flush.
  - Stale entities edited by other worker threads and decrypted secrets kept in memory — accepted: they live in the host-owned EntityManager. Clearing the identity map between requests (`services_resetter` or `EntityManager::clear()` in the host loop) remains the application's responsibility under scenario B. No Api Studio read is a security decision that needs a forced refresh (access is decided by roles, not DB rows).

### W-03 — Outbound execution can pin a worker thread for a long time (Low)

- **Where:** `src/Service/RequestExecutor.php:128-136` passes `timeout => request_timeout_seconds` to HttpClient. That is an idle timeout, and no `max_duration` is set. `:195-199` builds `SoapClient` with only `connection_timeout`, so reads fall back to the `default_socket_timeout` ini value. `src/Security/ExecutionUrlValidator.php:76` calls `gethostbyname()`, which uses the system resolver timeout. `request_timeout_seconds` defaults to 30 and accepts up to 300 (`src/DependencyInjection/Configuration.php:74-78`).
- **Worker impact:** a slow or trickling target can hold one of the limited worker threads for much longer than the configured value (for example a server that sends a byte every few seconds). No state leaks.
- **Recommendation:** add `max_duration` equal to the timeout for HttpClient, set `default_socket_timeout` (or a stream context) for SOAP, keep resolver `options timeout:1 attempts:2`, and cap waiting requests with FrankenPHP `max_wait_time`.
- **Status:** Resolved for HTTP/GraphQL — `src/Service/RequestExecutor.php` passes `max_duration` = `request_timeout_seconds` (test in `tests/Unit/Service/RequestExecutorTimeoutTest.php`). Accepted for SOAP reads and DNS: ext-soap only honours the process-wide `default_socket_timeout`, and changing it at runtime with `ini_set()` is itself worker-unsafe; set it in `php.ini` together with resolver options and FrankenPHP `max_wait_time`.

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

- Keeping Symfony's `services_resetter` active is still recommended so the host EntityManager is cleared between requests; the bundle itself no longer depends on the Twig global reset.
- Keep `request_timeout_seconds` low (the default is 30) and configure `execution_url_allowlist` so the studio cannot be used to reach slow or unexpected hosts.
- Api Studio is an internal tool. Prefer running it on an admin host or with few worker threads so long outbound calls cannot starve public traffic.
- A custom `security.access_checker` service or template overrides must not keep per-request state in service properties.
- If memory grows over time, set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) to recycle workers.
- Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` runs the demo with a `worker` block.

## Re-audit triggers

Re-run this audit when a change adds: a new Twig global or `GlobalsInterface` extension (request-dependent values must be Twig functions), properties to `RequestExecutor` / `ExecutionUrlValidator` / importers, a cache of workspaces or environments, new Doctrine listeners that buffer entities, a new outbound client (HTTP, SOAP, gRPC), or any use of `$_SERVER` / `$_ENV` / `ini_set` at runtime.
