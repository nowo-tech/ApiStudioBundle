# Upgrading

## Table of contents

- [General](#general)
- [Before upgrading](#before-upgrading)
- [To 1.0.0](#to-100)
- [Database schema](#database-schema)

## General

Follow [CHANGELOG.md](CHANGELOG.md) for breaking changes between versions.

## Before upgrading

1. Read the release notes on GitHub.
2. Run your test suite and `composer audit`.
3. Back up the database if you store Api Studio entities in production.

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
