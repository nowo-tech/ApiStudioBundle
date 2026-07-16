# Upgrading

## Table of contents

- [General](#general)
- [Before upgrading](#before-upgrading)
- [To 1.0.1](#to-101)
- [To 1.0.0](#to-100)
- [Database schema](#database-schema)

## General

Follow [CHANGELOG.md](CHANGELOG.md) for breaking changes between versions.

## Before upgrading

1. Read the release notes on GitHub.
2. Run your test suite and `composer audit`.
3. Back up the database if you store Api Studio entities in production.

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
