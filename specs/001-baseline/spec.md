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

## Success Criteria

- **SC-001**: **138/138** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-10-30; `git ls-files src | wc -l`).
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
