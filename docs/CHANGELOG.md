# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Minimum supported Symfony version is **7.0** (`^7.0 || ^8.0`); PHP remains `>=8.2 <8.6`.

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
- Symfony `^7.0 || ^8.0`
- Doctrine ORM `^2.15 || ^3.0` / DoctrineBundle `^2.10 || ^3.0`

See [Upgrading](UPGRADING.md) and [Release](RELEASE.md).

[1.0.0]: https://github.com/nowo-tech/ApiStudioBundle/releases/tag/v1.0.0
