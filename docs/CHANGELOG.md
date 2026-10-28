# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
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

### Performance

- **Sidebar tree in one query.** The navigation tree rendered on every studio page walked the lazy collections
  (workspaces → services → endpoints → request examples): 1 + W + S + E queries per request. It is now built from one
  scalar query (`ApiWorkspaceRepository::navigationRows()`), same order and content; a functional test pins the
  query count.

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
