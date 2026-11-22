# Feature Specification: ApiStudioBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  

**Package**: `nowo-tech/api-studio-bundle`  
**Configuration root**: `nowo_api_studio`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Postman/Apidog-style **API studio** inside Symfony: workspaces, services, environments, endpoints (REST/SOAP/GraphQL), request/response examples, in-browser **tester**, import/export (OpenAPI, Postman), execution history, and configurable access control.

---

## User Scenarios

### US-01 — Organize API collections (P1)

**Given** workspaces and services, **When** integrator creates endpoints with translations, **Then** CRUD UI and sidebar tree reflect hierarchy.

### US-02 — Execute requests (P1)

**Given** environment variables and auth config, **When** user runs the API tester, **Then** `RequestExecutor` resolves variables, validates URL, and returns `ApiExecutionResult`.

### US-03 — Import/export (P2)

**Given** OpenAPI or Postman files, **When** import runs, **Then** endpoints and examples merge into Doctrine entities with slug deduplication.

### US-04 — Multi-environment (P2)

**Given** dev/test/prod environment records, **When** switching environment in UI, **Then** `EnvironmentContextBuilder` supplies base URLs and variables.

### US-05 — Access control (P1)

**Given** `ApiStudioAccessCheckerInterface`, **When** user opens dashboard routes, **Then** `ApiStudioAccessSubscriber` denies unauthorized access.

---

## Requirements

### Bundle & persistence

- **FR-BUNDLE-001**: `ApiStudioBundle` + alias `nowo_api_studio`.
- **FR-CFG-001**: Config: `enabled`, `connection`, `table_prefix`, `environments`, `ui.path`, locale defaults.
- **FR-ORM-001**: Entities workspace → service → endpoint with environments, variables, examples, history.
- **FR-ORM-002**: Repositories for all entities; table prefix via `TablePrefixSubscriber`.
- **FR-ORM-003**: New workspaces are seeded with the configured `environments` (`WorkspaceEnvironmentInitializer`); a closed Api Studio entity manager is reset at request start (`EntityManagerRecoverySubscriber`, FrankenPHP worker safety).

### HTTP UI

- **FR-CTRL-001**: Dashboard, workspace/service/endpoint CRUD, examples, import/export, locale switcher, execute controller.
- **FR-FORM-001**: Form types for CRUD + `JsonMapType` + import upload; client-side and CSRF-only form views (`ClientSideFieldsType`, `CsrfOnlyType`, `KeyValueRowType`, `EndpointTesterFormFactory`) so no template contains raw form controls (REQ-TWIG-005).
- **FR-TWIG-001**: Page templates on the UiKit scaffolds, workbench (API tree), tester panels, locale tabs, overridable under `templates/bundles/NowoApiStudioBundle/` (see inventory and `docs/TEMPLATES.md`).
- **FR-UI-003**: UiKit page contract (REQ-UI-003): every page extends a UiKit scaffold (`dashboard`, `list`, `show`, `form`) fed by view models from `ApiStudioPageFactory`; the layout is `ui.layout_template` or `nowo_ui_kit.layouts.admin` (`ApiStudioPages`, deprecated bridge for pre-1.10 layouts); deletes use the UiKit confirm contract; no CDN, no inline handler; enforced by `PageContractTestCase`.

### Execution engine

- **FR-EXEC-001**: `RequestExecutor` performs HTTP/SOAP calls with auth headers and body from examples.
- **FR-EXEC-002**: `VariableResolver` / `VariableSyntax` expand `{{var}}` placeholders.
- **FR-EXEC-003**: `ExecutionUrlValidator` blocks SSRF/open redirects per config.
- **FR-EXEC-004**: `PayloadBodyHelper` normalizes JSON/form bodies.
- **FR-EXEC-005**: SOAP calls go through the guarded HTTP client (`GuardedSoapClient`, `GuardedWsdlLoader`); raw-envelope mode (`SoapEnvelope`) works without ext-soap.

### Import/export

- **FR-IMP-001**: OpenAPI and Postman importers; OpenAPI + env var exporters.
- **FR-IMP-002**: `DocumentParser` detects format; `SlugHelper` ensures unique slugs.

### CLI & schema

- **FR-CLI-001**: `SyncSchemaCommand` aligns Doctrine schema; `SeedDemoCommand` seeds demo data via `DemoSeedService`.

### Security & i18n

- **FR-SEC-001**: `ConfigurableApiStudioAccessChecker` + interface for host app policies.
- **FR-SEC-002**: Secret environment variable values are encrypted at rest (`SecretValueCipher`, sodium secretbox) by `SecretVariableEncryptionListener`; entities hold plaintext only in memory.
- **FR-SEC-003**: `ApiStudioSecurityPass` requires SecurityBundle and always registers the access subscriber; with `enabled: false` every Api Studio route answers 404.
- **FR-OBS-001**: `HistorySanitizer` redacts secrets/PII from request history (URL query, headers, bodies) before persistence.
- **FR-I18N-001**: Seven locale YAML files; `LocaleManager` + `LocaleSubscriber`.

### Frontend

- **FR-UI-001**: TypeScript modules (tester, shell, body tools, script runtime) built to `Resources/public/*.js`.
- **FR-UI-002**: Workbench / console CSS mapped to the UiKit `--nowo-ui-*` tokens (no global element rules, no own palette).

---

### Requirements added by the inventory sweep (2026-11-16)

Areas introduced by releases after the baseline review; each requirement names the area and what its main source does (class docblock).

- **FR-EVENT-001** `src/Event/`: Dispatched after the studio executed a request and stored its history row (1.12, architecture bar rules 4 and 7).
- **FR-ROUTING-001** `src/Routing/`: Route loader behind `.
- **FR-SECRET-001** `src/Secret/`: A host secret reference to resolve (1.13, ApiStudioSecretSourceInterface), with the context the host authorises it in.
- **FR-TENANCY-001** `src/Tenancy/`: Opaque owner of a workspace: a `(type, id)` pair chosen by the host (e.g. `('organization', <public uuid>)`).
- **FR-VALIDATOR-001** `src/Validator/`: Slug format (1.11): lowercase letters and digits, single `-` or `_` between them, at most 128 characters.

### Requirements added by the inventory sweep (2026-11-22)

- **FR-SEED-001** `src/Seed/`: `WorkspaceSeeder` creates or updates one workspace from a `WorkspaceSeed` (idempotent by natural keys; secret variables only as host secret references, never values) and dispatches `ApiWorkspaceSeededEvent` once committed (1.17).
- **FR-SCOPE-001** `src/Scope/`: host sub-scopes of a workspace (`WorkspaceScope`, `ScopeContext`, resolver / access-checker / label-provider contracts); `WorkspaceScopeMover` changes the scope and dispatches `ApiWorkspaceScopeChangedEvent` once committed (1.17).
- **FR-SEARCH-001** `src/Search/`, `src/Bridge/SearchKit/`: the global search groups (workspaces and services by name, endpoints by name, path and method), per-result access checks and the `ApiStudioSearchContextEvent` host hook (1.18).
- **FR-QUERY-001** `src/Query/`: scalar read port for listing and counting workspaces without DQL over the bundle tables (`ApiWorkspaceQueryInterface`, 1.19).
- **FR-CONCUR-001** `ConcurrencyConflictSubscriber`: lock conflicts on Api Studio routes answer with the CONFLICT codes (1.17, REQ-RUNTIME-003).

### Requirements added in 1.20 (row versions of the edit forms)

- **FR-CONCUR-002** Row versions (REQ-RUNTIME-003 rule 4): the workspace, service, endpoint and environment carry a
  `row_version` column (`INTEGER NOT NULL DEFAULT 1`). Their edit forms render it in a hidden field and save only on
  that version (`RowVersionGuard::claim()`: a guarded atomic `UPDATE` in the save's transaction); a stale save answers
  the **domain** stale-edit answer — 409 with `X-Error-Code: API_STUDIO_STALE_EDIT`, no `X-Conflict-Code` and no
  `Retry-After` (1.21; until 1.20 it answered the retryable `CONFLICT_001`) — shows the stored data and the message
  `conflict.stale_edit`, and writes nothing. Every other ORM write of
  those rows, and of endpoint translations and environment variables, moves the version atomically
  (`RowVersionListener`).

## Success Criteria

- **SC-001**: **278/278** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-11-22; `find src -type f ! -name '*.test.ts' | wc -l`).
- **SC-002**: Config matches `Configuration.php` and [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: PHPUnit + PHPStan pass; PHP line coverage ≥ 99% of every `src/` class (CI gate, REQ-TEST-003); page contract test green; Playwright happy path in the demo.

---

## Explicit non-goals

- Replacing dedicated API gateways or production traffic management.
- Guaranteed SOAP/GraphQL client features beyond documented executor scope.

---

## Validation

```bash
composer test          # PHPUnit
composer cs-check      # PHP-CS-Fixer (dry run)
composer phpstan       # PHPStan (incl. FrankenPHP rulesets)
make release-check     # full pre-release gate (Docker)
git ls-files src | wc -l   # must equal the inventory total (SC-001)
```
