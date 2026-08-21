# API Studio Bundle

[![CI](https://github.com/nowo-tech/ApiStudioBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ApiStudioBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/api-studio-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/api-studio-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/api-studio-bundle.svg)](https://packagist.org/packages/nowo-tech/api-studio-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/api-studio-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/ApiStudioBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/api-studio-bundle) and give the repo a star on GitHub.

Manage, document, and test REST, SOAP, and GraphQL APIs from a built-in dashboard — your own Postman / Apidog inside Symfony.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Features

- **Workspaces** — group services, environments, and endpoints
- **Services** — third-party or internal APIs (REST, SOAP, GraphQL)
- **Environments & variables** — `{{base_url}}`, tokens, secrets per environment
- **Endpoints** — method, path, headers, body templates, SOAP actions
- **Multilingual docs** — per-endpoint translations (title, description, notes)
- **Request/response examples** — document sample payloads
- **Request console** — execute and inspect live responses from the UI
- **History** — last executions persisted per endpoint
- **Import / Export** — OpenAPI 3 & Swagger 2, Postman collections, environment variables (JSON/YAML/.env)
- **Browser scripts** — pre/post-request JavaScript in the console (`pm.environment.set`, `pm.response.json`, tests)

## Installation

```bash
composer require nowo-tech/api-studio-bundle
```

Register the bundle and hard dependencies (Flex usually does this):

```php
// config/bundles.php
Nowo\ApiStudioBundle\ApiStudioBundle::class => ['all' => true],
Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
```

Install the Asset component (required for Twig `asset()` in the request console):

```bash
composer require symfony/asset
```

Import routes:

```yaml
# config/routes.yaml
nowo_api_studio:
    resource: '@NowoApiStudioBundle/Resources/config/routes.yaml'
```

Configure:

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    enabled: true
    ui:
        path: '/api-studio'
        locales: [en, es]
    security:
        access_roles: [ROLE_ADMIN]
```

Sync schema and optional demo data:

```bash
php bin/console nowo:api-studio:sync-schema
php bin/console nowo:api-studio:seed-demo
make assets
php bin/console assets:install
```

Open `/api-studio` in your browser.

## Requirements

- PHP >= 8.2 < 8.6
- Symfony 7.0+ or 8.x
- Doctrine ORM
- `ext-json`
- `ext-soap` (optional, for SOAP execution)
- [UiKitBundle](https://github.com/nowo-tech/UiKitBundle) `^1.4`
- [FormKitBundle](https://github.com/nowo-tech/FormKitBundle) `^2.0`
- `twig/extra-bundle` + `twig/string-extra` `^3.12` (REQ-TWIG-004)

## Browser scripts (pre/post-request)

Scripts run **in the browser** before and after each test request — ideal for chaining auth flows and updating environment variables without server-side code execution.

```javascript
// Pre-request — set timestamp or override token before send
pm.environment.set('timestamp', Date.now().toString());

// Post-request — extract token from response for next call
const data = pm.response.json();
if (data && data.data && data.data.token) {
  pm.environment.set('access_token', data.data.token);
}
pm.test('Status is 200', function () {
  pm.expect(pm.response.code).to.equal(200);
});
```

- **Tabs**: Pre-request / Post-request in the endpoint console (editable per session; save via endpoint edit form to persist)
- **Service scripts**: optional pre/post at service level (always run first/last)
- **Runtime variables**: stored in `sessionStorage` for the tab session; preview URL updates immediately
- **Persist**: checkbox **Persist variable changes** saves script-modified keys to the selected environment in the database

API available: `pm.environment.get/set`, `pm.variables`, `pm.request.body`, `pm.response.json()`, `pm.test()`, `pm.console.log()`.

## Import & Export

From any workspace (`/api-studio/workspaces/{id}` → **Import / Export**):

| Action | Formats |
|--------|---------|
| Import API spec | OpenAPI 3.x, Swagger 2.0 (`.json`, `.yaml`) |
| Import Postman | Collection v2.x (`.json`), optional collection variables |
| Import variables | JSON, YAML, `.env` (merge or replace) |
| Export OpenAPI | Workspace or single service → JSON |
| Export variables | Per environment or whole workspace → JSON, YAML, `.env` |

Service-level import adds endpoints to an existing service. Workspace-level import creates a new REST service.

## Frontend assets (TypeScript)

Browser scripts live in `src/Resources/assets/src/` and compile to `src/Resources/public/` with **pnpm** + Vite:

```bash
make assets          # pnpm install && pnpm run build (inside Docker)
pnpm run build       # compile only (host, if pnpm is installed)
pnpm run typecheck   # tsc --noEmit
```

Entry points: `api-tester`, `api-body-tools`, `api-script-runtime`, `api-studio-shell`, `api-endpoint-doc`, `api-form-locale-tabs`.

## Demo

```bash
make -C demo up-symfony8
```

Default URL: http://localhost:8023 (override with `PORT`).

Optional FrankenPHP mode via `FRANKENPHP_MODE=worker|classic` in `demo/symfony8/.env` (see [demo/README.md](demo/README.md) and [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md)).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Demo FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

```bash
make test
make test-coverage
```

- PHP: **100%** of lines in the covered `src/` surface (`phpunit.xml.dist` excludes Controllers, Forms, Entities, and other DI/wiring justified as integration-heavy; see that file’s `<source><exclude>`)
- TS/JS: **N/A** (assets are built with Vite; no Vitest suite yet)
- Python: **N/A**

PHP coverage is reported in CI and via `make test-coverage` (see README badge and release-check).

## License

MIT — see [LICENSE](LICENSE).
