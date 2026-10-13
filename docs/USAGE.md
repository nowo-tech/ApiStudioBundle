# Usage

## Table of contents

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

Before persistence, `HistorySanitizer` redacts sensitive headers (`Authorization`, API keys, cookies, …) and common secret patterns in bodies. See [SECURITY.md](SECURITY.md).

Outbound calls use `nowo_api_studio.ui.request_timeout_seconds` (default **30**). See [CONFIGURATION.md](CONFIGURATION.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## Auth on services

Configure `auth_type` on the service:

- `none`, `basic`, `bearer`, `api_key`, `custom`
- Auth config values support `{{variables}}` from the selected environment

### SOAP services

SOAP executions send the same HTTP headers as REST: the service auth (`basic` / `bearer` → `Authorization`, `api_key` → the configured header, default `X-Api-Key`), the service default headers, the endpoint headers and the headers edited in the request console (which replace the service/endpoint defaults, as for REST; auth is applied on top). Header precedence:

1. `Content-Type` and `SOAPAction` are owned by the SOAP transport (`text/xml` + `SOAPAction: "<action>"` for SOAP 1.1, `application/soap+xml; action="<action>"` for SOAP 1.2). A header with one of these names (any case) from the service, endpoint or console is **dropped**, never sent twice or allowed to override them; set the action with the endpoint's **SOAP action** field. The endpoint content type is not used for SOAP.
2. Auth headers override a service/endpoint/console header with the same name.
3. Endpoint headers override service default headers; console headers replace both.

The same headers (without the framing ones) are sent when downloading the WSDL, so WSDLs behind auth work, but only to documents on the **same origin** (scheme, host, port) as the WSDL URL: `<xsd:import>` / `<wsdl:import>` documents on another host are fetched without them so credentials never leak to a third party. Redirects follow `execution_max_redirects`; when a redirect leaves the host, the HTTP client drops `Authorization`/`Cookie` but custom headers (e.g. an API key header) are forwarded, so keep `execution_max_redirects: 0` (default) for credentialed SOAP services unless you trust the redirect targets. API key auth is header-only (there is no query-string variant, for REST or SOAP).

Executions are stored in history with the headers that were sent (framing headers excluded), redacted by `HistorySanitizer` exactly like REST: the auth header, the configured API key header and values of secret environment variables are replaced by `[REDACTED]`, and secret values are masked in the stored SOAP envelope.

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
| `base.html.twig` | Page shell: extends `nowo_api_studio_layout_template` and stacks Api Studio CSS/JS with `{{ parent() }}` |
| `layout.html.twig` | Default full HTML chrome (rail, sidebar, topbar) when `ui.layout_template` points here |
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

**Layout chain (REQ-UI-001):** pages extend `@NowoApiStudioBundle/base.html.twig`, which extends the configurable layout (`nowo_api_studio.ui.layout_template` / Twig global `nowo_api_studio_layout_template`), loads **UiKit** (`nowo-ui.css` / macros from `nowo-tech/ui-kit-bundle`), and injects theme CSS/JS via `{{ parent() }}`. Child templates that override `javascripts` / `stylesheets` must call `{{ parent() }}` so host and Api Studio assets still load.

Protect the UI with Symfony `access_control` on the configured path (default `/api-studio`). See [CONFIGURATION.md](CONFIGURATION.md) (`security.access_roles`, `security.allow_unauthenticated`).

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
