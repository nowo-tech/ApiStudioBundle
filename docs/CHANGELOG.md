# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.8] - 2026-07-28](#108-2026-07-28)
- [[1.0.7] - 2026-07-27](#107-2026-07-27)
- [[1.0.6] - 2026-07-27](#106-2026-07-27)
- [[1.0.5] - 2026-07-27](#105-2026-07-27)
- [[1.0.4] - 2026-07-27](#104-2026-07-27)
- [[1.0.3] - 2026-07-23](#103-2026-07-23)
- [[1.0.2] - 2026-07-22](#102-2026-07-22)
- [[1.0.1] - 2026-07-16](#101-2026-07-16)
- [[1.0.0] - 2026-07-16](#100-2026-07-16)

## [Unreleased]

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
