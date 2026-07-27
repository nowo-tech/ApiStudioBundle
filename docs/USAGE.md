# Usage

## Contents

- [Workspaces](#workspaces)
- [Documenting endpoints](#documenting-endpoints)
- [Demo seed](#demo-seed)
- [Testing requests](#testing-requests)
- [Auth on services](#auth-on-services)
- [Overriding Twig templates (REQ-TWIG-001)](#overriding-twig-templates-req-twig-001)
- [Translations (REQ-I18N)](#translations-req-i18n)

## Workspaces

Create a workspace per product, client, or team. Each workspace owns:

- **Services** — API providers (REST/SOAP/GraphQL)
- **Environments** — variable sets (`local`, `staging`, `prod`)
- **Endpoints** — individual operations

## Documenting endpoints

For each endpoint you can add:

- Translations per locale (`title`, `description`, `notes`)
- Request examples (headers, query, body)
- Response examples (status, headers, body)

## Demo seed

```bash
php bin/console nowo:api-studio:seed-demo
php bin/console nowo:api-studio:seed-demo --fresh
```

Includes reference catalogs for:

- **JSONPlaceholder** — live REST (no auth)
- **LinkedIn API v2** — profile, UGC posts, organization search
- **Google Cloud Translation** — translate, detect, list languages
- **Catastro (España)** — SOAP `Consulta_CPMRC` / `Consulta_DNPRC` and HTTP variants

Configure credentials in **Environments** before calling real APIs.

## Testing requests

Open an endpoint detail page, pick an environment, edit the body if needed, and click **Send request**. Results and timing are shown inline; executions are stored in history.

Outbound calls use `nowo_api_studio.ui.request_timeout_seconds` (default **30**). See [CONFIGURATION.md](CONFIGURATION.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## Auth on services

Configure `auth_type` on the service:

- `none`, `basic`, `bearer`, `api_key`, `custom`
- Auth config values support `{{variables}}` from the selected environment

## Overriding Twig templates (REQ-TWIG-001)

Bundle templates use the **`@NowoApiStudioBundle`** namespace.

### Override in the application

Create files under:

```
templates/bundles/NowoApiStudioBundle/
```

Symfony resolves application overrides **before** bundle defaults. Copy only the templates you need to change.

### Overridable templates

| Subpath | Purpose |
| --- | --- |
| `layout.html.twig` | Main dashboard shell (nav, assets) |
| `dashboard/index.html.twig` | Landing dashboard |
| `workspace/index.html.twig` | Workspace list |
| `workspace/show.html.twig` | Workspace detail |
| `workspace/form.html.twig` | Create/edit workspace |
| `service/index.html.twig` | Service list |
| `service/show.html.twig` | Service detail |
| `service/form.html.twig` | Create/edit service |
| `environment/index.html.twig` | Environment list |
| `environment/show.html.twig` | Environment detail |
| `environment/form.html.twig` | Create/edit environment |
| `endpoint/index.html.twig` | Endpoint list |
| `endpoint/show.html.twig` | Endpoint detail / request console |
| `endpoint/form.html.twig` | Create/edit endpoint |
| `import_export/hub.html.twig` | Import/export hub |
| `import_export/import.html.twig` | Import form |
| `import_export/_workspace_panel.html.twig` | Workspace import/export panel partial |
| `_locale_switcher.html.twig` | Locale switcher partial |
| `_sidebar_tree.html.twig` | Sidebar tree partial |
| `_sidebar_endpoints.html.twig` | Sidebar endpoints partial |
| `_endpoint_expandable_list.html.twig` | Expandable endpoint list partial |
| `_service_collection_list.html.twig` | Service collection list partial |
| `_service_kebab_menu.html.twig` | Service actions menu partial |
| `_service_action_modals.html.twig` | Service action modals partial |
| `_variable_catalog.html.twig` | Variable catalog partial |

Example:

```twig
{# templates/bundles/NowoApiStudioBundle/layout.html.twig #}
{% extends '@NowoApiStudioBundle/layout.html.twig' %}
```

Prefer extending or including the original `@NowoApiStudioBundle/...` templates so you keep assets and blocks stable.

Protect the UI with Symfony `access_control` on the configured path (default `/api-studio`). See [CONFIGURATION.md](CONFIGURATION.md) (`security.access_roles`).

## Translations (REQ-I18N)

Domain: **`NowoApiStudioBundle`**

The bundle ships catalogues for **`de`**, **`en`**, **`es`**, **`fr`**, **`it`**, **`nl`**, and **`pt`** under `src/Resources/translations/`.

### Override in the application

```yaml
# translations/NowoApiStudioBundle.es.yaml
workspace:
    title: Mis espacios de trabajo
```

Symfony uses application translations first; missing keys fall back to the bundle.

UI/documentation locales are configured via `nowo_api_studio.ui.locales` (see [CONFIGURATION.md](CONFIGURATION.md)).
