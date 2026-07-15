# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Bundle responsibilities](#bundle-responsibilities)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Scope

Api Studio Bundle provides a **web UI and persistence layer** for designing, documenting, and executing HTTP/SOAP/GraphQL API requests against configured environments.

The bundle **does not replace** Symfony Security. Authentication, firewall rules, and network perimeter controls remain the responsibility of the host application.

## Attack surface

| Input / surface | Description |
| --- | --- |
| HTTP UI | Dashboard, workspaces, services, endpoints, environment variables, import/export, request execution |
| Outbound HTTP | REST/GraphQL via Symfony HttpClient; SOAP via `SoapClient` and WSDL URLs |
| Database | Workspaces, services, endpoints, env variables (including `secret` flag), request history |
| CLI | Schema sync, demo seed (operator-controlled) |
| Configuration | `nowo_api_studio.ui.required_roles`, `execution_url_allowlist`, request timeout |

## Threat model

| Area | Risk | Mitigation |
| --- | --- | --- |
| Unauthorized UI access | Unauthenticated users execute requests or read secrets | `ui.required_roles` (default `ROLE_ADMIN`); app `access_control` on `/api-studio` |
| SSRF | Authenticated user targets internal services (metadata, Redis, admin panels) | `ExecutionUrlValidator` blocks private/local IPs; optional `execution_url_allowlist` |
| Secret storage | API keys/tokens in env variables persisted in DB | `secret` flag for UI masking; app should encrypt at rest or use external secret store |
| Request history | Headers/bodies may contain tokens and PII | Retention policy in app; restrict dashboard roles |
| Import/export | JSON export may contain credentials | Restrict access; scan exports before sharing |
| XSS | Twig templates, stored endpoint names/descriptions | Twig auto-escape; do not disable escaping in overrides |
| CSRF | State-changing UI actions | CSRF tokens on execute/delete/sync endpoints |
| DoS | Large payloads, slow upstream APIs | Configurable `request_timeout_seconds` (1–300) |
| SOAP WSDL | `SoapClient` loads arbitrary WSDL URL | Same SSRF validator applied to WSDL/base URLs |

## Application responsibilities

- Configure Symfony Security (`security.yaml`) with firewall and `access_control` for the Api Studio path
- Set `nowo_api_studio.ui.required_roles` appropriately (never leave empty in production unless intentionally public)
- Configure `execution_url_allowlist` in production when targets are known
- Run `composer audit` in the application
- Do not commit `.env` or secrets; rotate env variables stored in Api Studio DB
- Redact or disable request history for sensitive environments

## Bundle responsibilities

- Block SSRF to private/local networks before outbound requests
- Enforce role checks on Api Studio routes when `required_roles` is configured
- CSRF protection on mutating controller actions
- Validate `table_prefix` (alphanumeric + underscore only)
- Document threat model and release checklist in this file

## Release security checklist (12.4.1)

Before each release, confirm:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` listed in `.gitignore`; no secrets in repo | ☐ |
| Flex recipe / default config contain no secrets | ☐ |
| `ui.required_roles` defaults to `ROLE_ADMIN` (or documented override) | ☐ |
| SSRF validator covers REST, GraphQL, and SOAP/WSDL URLs | ☐ |
| User input validated (forms + Symfony validator) | ☐ |
| Output escaped (Twig templates) | ☐ |
| `composer audit` run on bundle and demo | ☐ |
| Logs/history do not dump credentials by default | ☐ |
| Safe cryptography N/A (no custom crypto in bundle) | ☐ |
| Permissions/exposure documented for integrators | ☐ |
| DoS limits: request timeout configured | ☐ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
