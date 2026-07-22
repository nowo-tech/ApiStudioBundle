# API Studio Bundle

[![CI](https://github.com/nowo-tech/ApiStudioBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ApiStudioBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/api-studio-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/api-studio-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/api-studio-bundle.svg)](https://packagist.org/packages/nowo-tech/api-studio-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7%2B%20%7C%208%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/api-studio-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/ApiStudioBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

Manage, document, and test REST, SOAP, and GraphQL APIs from a built-in dashboard — your own Postman / Apidog inside Symfony.

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

## Requirements

- PHP >= 8.2 < 8.6
- Symfony 7.0+ or 8.x
- Doctrine ORM
- `ext-json`
- `ext-soap` (optional, for SOAP execution)

## Installation

```bash
composer require nowo-tech/api-studio-bundle
```

Register the bundle:

```php
// config/bundles.php
Nowo\ApiStudioBundle\ApiStudioBundle::class => ['all' => true],
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

## Frontend assets (TypeScript)

Browser scripts live in `src/Resources/assets/src/` and compile to `src/Resources/public/`:

```bash
make assets          # npm install && npm run build
npm run build        # compile only
npx tsc --noEmit     # typecheck
```

Entry points: `api-tester`, `api-body-tools`, `api-script-runtime`, `api-studio-shell`, `api-endpoint-doc`, `api-form-locale-tabs`.

## Demo

```bash
make -C demo up-symfony8
```

Default URL: http://localhost:8023 (override with `PORT`).

Optional FrankenPHP mode via `FRANKENPHP_MODE=worker|classic` in `demo/symfony8/.env` (see [demo/README.md](demo/README.md)).

## Documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
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

## Tests and coverage

```bash
make test
make test-coverage
```

PHP coverage is reported in CI and via `make test-coverage` (see README badge and release-check).

## Found this useful?

If this bundle helps your project, consider starring the repository or opening an issue with feedback.

## License

MIT — see [LICENSE](LICENSE).
