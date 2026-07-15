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

### HTTP UI

- **FR-CTRL-001**: Dashboard, workspace/service/endpoint CRUD, examples, import/export, locale switcher, execute controller.
- **FR-FORM-001**: Form types for CRUD + `JsonMapType` + import upload.
- **FR-TWIG-001**: Layout, sidebar tree, tester panels, locale tabs (see inventory).

### Execution engine

- **FR-EXEC-001**: `RequestExecutor` performs HTTP/SOAP calls with auth headers and body from examples.
- **FR-EXEC-002**: `VariableResolver` / `VariableSyntax` expand `{{var}}` placeholders.
- **FR-EXEC-003**: `ExecutionUrlValidator` blocks SSRF/open redirects per config.
- **FR-EXEC-004**: `PayloadBodyHelper` normalizes JSON/form bodies.

### Import/export

- **FR-IMP-001**: OpenAPI and Postman importers; OpenAPI + env var exporters.
- **FR-IMP-002**: `DocumentParser` detects format; `SlugHelper` ensures unique slugs.

### CLI & schema

- **FR-CLI-001**: `SyncSchemaCommand` aligns Doctrine schema; `SeedDemoCommand` seeds demo data via `DemoSeedService`.

### Security & i18n

- **FR-SEC-001**: `ConfigurableApiStudioAccessChecker` + interface for host app policies.
- **FR-I18N-001**: Seven locale YAML files; `LocaleManager` + `LocaleSubscriber`.

### Frontend

- **FR-UI-001**: TypeScript modules (tester, shell, body tools, script runtime) built to `Resources/public/*.js`.
- **FR-UI-002**: Theme CSS for studio shell.

---

## Success Criteria

- **SC-001**: **117/117** files mapped in inventory.
- **SC-002**: Config matches `Configuration.php` and [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: PHPUnit + PHPStan pass; manual tester smoke in demo.

---

## Explicit non-goals

- Replacing dedicated API gateways or production traffic management.
- Guaranteed SOAP/GraphQL client features beyond documented executor scope.

---

## Validation

`composer qa`, demo `make release-check`, inventory row audit.
