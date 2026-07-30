# Upgrading

## Table of contents

- [General](#general)
- [Before upgrading](#before-upgrading)
- [To 1.0.13](#to-1013)
- [To 1.0.12](#to-1012)
- [To 1.0.11](#to-1011)
- [To 1.0.10](#to-1010)
- [To 1.0.9](#to-109)
- [To 1.0.8](#to-108)
- [To 1.0.7](#to-107)
- [To 1.0.6](#to-106)
- [To 1.0.5](#to-105)
- [To 1.0.4](#to-104)
- [To 1.0.3](#to-103)
- [To 1.0.2](#to-102)
- [To 1.0.1](#to-101)
- [To 1.0.0](#to-100)
- [Database schema](#database-schema)

## General

Follow [CHANGELOG.md](CHANGELOG.md) for breaking changes between versions.

## Before upgrading

1. Read the release notes on GitHub.
2. Run your test suite and `composer audit`.
3. Back up the database if you store Api Studio entities in production.

## To 1.0.13

From **1.0.12** — backward compatible Twig layout chain.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console cache:clear
```

No configuration or schema changes.

**Twig:** pages now extend `@NowoApiStudioBundle/base.html.twig` (which extends `ui.layout_template` / `nowo_api_studio_layout_template` and stacks theme assets with `{{ parent() }}`). If you overrode page templates and still `{% extends nowo_api_studio_layout_template %}`, switch to `@NowoApiStudioBundle/base.html.twig` (or keep calling `{{ parent() }}` in `stylesheets` / `javascripts`). See [USAGE.md](USAGE.md) and [CONFIGURATION.md](CONFIGURATION.md).

## To 1.0.12

From **1.0.11** — maintainer Makefile only.

```bash
composer update nowo-tech/api-studio-bundle
```

No application changes. Makefiles probe Compose V2 with an absolute `docker` path, then fall back to `docker-compose`.

## To 1.0.11

From **1.0.10** — maintainer Makefile only.

```bash
composer update nowo-tech/api-studio-bundle
```

No application changes. Root/demo Makefiles detect Compose V2 first (`docker compose`), then fall back to `docker-compose`. The demo Makefile uses an absolute `docker` binary path so a local `docker/` config directory is not executed when `.` is on `PATH`.

## To 1.0.10

From **1.0.9** — CI/demo tooling only.

```bash
composer update nowo-tech/api-studio-bundle
```

No application changes. Demo Makefile prefers `docker compose` when the legacy `docker-compose` binary is missing.

## To 1.0.9

From **1.0.8** — backward compatible maintainer/CI fix only.

```bash
composer update nowo-tech/api-studio-bundle
```

No application changes. Root/demo Makefiles skip monorepo `update-deps` includes when those shared scripts are absent (standalone GitHub checkout).

## To 1.0.8

From **1.0.7** — backward compatible; observability and history hygiene.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console cache:clear
```

No configuration or schema changes.

**History:** new executions persist redacted headers/bodies via `HistorySanitizer` (Authorization, API-key headers, common password/token patterns). Existing history rows are unchanged. See [SECURITY.md](SECURITY.md) and [USAGE.md](USAGE.md).

**Logging:** `RequestExecutor` logs start/finish/failure with method + host only (no bodies or Authorization). Wire a monolog channel if you want these messages in the app log.

**CI / tests:** PHPUnit and CI set `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005). Maintainer smoke: `make demo-smoke`.

## To 1.0.7

From **1.0.6** — backward compatible bugfix release.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console cache:clear
```

No configuration or schema changes.

**Twig / i18n:** remaining UI partials now use translation domain `NowoApiStudioBundle` (same as forms). If you overrode those partials and still pass `nowo_api_studio`, update them.

**Layout:** shell theme CSS/JS live inside the `stylesheets` / `javascripts` blocks so host layouts that call `{{ parent() }}` keep Api Studio assets. Child templates that override those blocks must keep `{{ parent() }}`.

## To 1.0.6

From **1.0.5** — backward compatible; new security defaults encrypt secret env vars at rest.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console cache:clear
```

No database schema changes (ciphertext replaces plaintext in the existing `value` column on next save).

**Secrets:** `secrets.encrypt` defaults to `true`. Values marked `secret` are encrypted with sodium (`nowo_as_enc_v1:` prefix). Legacy plaintext rows still load and are re-encrypted on the next persist/update. Set `secrets.encryption_key` (or `NOWO_API_STUDIO_SECRETS_KEY`) if you rotate `kernel.secret` often.

**Allowlist:** `execution_url_allowlist_required` (default `false`) fails container compilation when the allowlist is empty. Set `true` in production with a non-empty `execution_url_allowlist`. See [SECURITY.md](SECURITY.md) and [CONFIGURATION.md](CONFIGURATION.md).

## To 1.0.5

From **1.0.4** — mostly backward compatible; check the notes below if you customized assets or form translations.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console assets:install
php bin/console cache:clear
```

No database schema changes.

**Assets:** the named package `nowo_api_studio` now uses `base_path: /bundles/apistudio` (Symfony `assets:install` output). If you overrode `framework.assets.packages.nowo_api_studio.base_path` to `/bundles/nowoapistudio`, remove or update that override.

**Forms / i18n:** form types use translation domain **`NowoApiStudioBundle`** (not `nowo_api_studio`). Move any app overrides from `translations/nowo_api_studio.*.yaml` to `translations/NowoApiStudioBundle.*.yaml`.

**Security:** new key `security.allow_unauthenticated` (default `false`). Enabling the UI without SecurityBundle now fails container compilation unless this flag is `true` (dev/demo only). Prefer `security.access_roles` + host `access_control` on `ui.path` in production. See [CONFIGURATION.md](CONFIGURATION.md) and [SECURITY.md](SECURITY.md).

**Optional UI:** `ui.layout_template` and `ui.css_framework` (`custom` default). Pages extend Twig global `nowo_api_studio_layout_template`. See [USAGE.md](USAGE.md).

## To 1.0.4

From **1.0.3** — backward compatible for integrators.

```bash
composer update nowo-tech/api-studio-bundle
```

No configuration or schema changes required for applications using the bundle.

Optional docs: Twig overrides and i18n domain are documented in [USAGE.md](USAGE.md). Request-console timeout hierarchy and FrankenPHP demos: [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) and [CONFIGURATION.md](CONFIGURATION.md) (`ui.request_timeout_seconds`).

**Demo only:** default host port is **8023** (was mismatched as `8022` in `.env.example`). Copy or align `PORT` from `.env.example` if you still use an old local `.env`.

**Maintainer-only:** frontend assets use **pnpm** (`make assets` / `pnpm-lock.yaml`). Contributors should use `make assets` (Docker) instead of npm/`package-lock.json`.

## To 1.0.3

From **1.0.2** — backward compatible for integrators.

```bash
composer update nowo-tech/api-studio-bundle
```

No configuration or schema changes required.

Maintainer-only: the package repo now requires `nowo-tech/phpstan-frankenphp` as a **dev** dependency when developing this bundle; consumers installing the Symfony bundle do not pull it transitively.

## To 1.0.2

From **1.0.1** — backward compatible for integrators.

```bash
composer update nowo-tech/api-studio-bundle
```

No configuration or schema changes required for applications using the bundle.

**Demo only:** the Symfony 8 FrankenPHP demo accepts `FRANKENPHP_MODE=worker|classic` (default `worker`). Copy from `.env.example` if needed and recreate containers after changing the mode. See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## To 1.0.1

From **1.0.0** — backward compatible.

```bash
composer update nowo-tech/api-studio-bundle
php bin/console cache:clear
```

No configuration or schema changes required.

Composer constraints now accept **Symfony 7.0+** (`^7.0 || ^8.0`) in addition to Symfony 8. PHP remains `>=8.2 <8.6`.

On Symfony 8, ensure Doctrine Bundle can resolve to `^3` (PHP 8.4+) if your application previously locked `doctrine/doctrine-bundle` 2.x.

## To 1.0.0

First stable release. Fresh install:

```bash
composer require nowo-tech/api-studio-bundle
php bin/console nowo:api-studio:sync-schema
php bin/console assets:install
```

Configure access (preferred):

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    security:
        access_roles: [ROLE_ADMIN]
```

`ui.required_roles` remains supported as a legacy fallback when `security.access_roles` is empty.

Review [CONFIGURATION.md](CONFIGURATION.md) for `execution_url_allowlist`, `table_prefix`, and UI options.

## Database schema

After upgrading the package, sync schema when migrations or entity mappings change:

```bash
php bin/console nowo:api-studio:sync-schema
```

Review `docs/CONFIGURATION.md` for new configuration keys.
