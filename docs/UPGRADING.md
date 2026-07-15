# Upgrading

## Table of contents

- [General](#general)
- [Before upgrading](#before-upgrading)
- [Database schema](#database-schema)

## General

Follow [CHANGELOG.md](CHANGELOG.md) for breaking changes between versions.

## Before upgrading

1. Read the release notes on GitHub.
2. Run your test suite and `composer audit`.
3. Back up the database if you store Api Studio entities in production.

## Database schema

After upgrading the package, sync schema when migrations or entity mappings change:

```bash
php bin/console nowo:api-studio:sync-schema
```

Review `docs/CONFIGURATION.md` for new configuration keys.
