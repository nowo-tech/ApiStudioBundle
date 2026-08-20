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
| Configuration | `security.access_roles`, `execution_url_allowlist`, `secrets.encrypt`, request timeout |

## Threat model

| Area | Risk | Mitigation |
| --- | --- | --- |
| Unauthorized UI access | Unauthenticated users execute requests or read secrets | `security.access_roles` (default `ROLE_ADMIN`); `allow_unauthenticated: false`; host `access_control` on `ui.path` |
| SSRF | Authenticated user targets internal services (metadata, Redis, admin panels) | `ExecutionUrlValidator` blocks private/local IPs; `execution_url_allowlist` + optional `execution_url_allowlist_required` |
| Secret storage | API keys/tokens in env variables persisted in DB | Variables marked `secret` are encrypted at rest with sodium (`secrets.encrypt`, default `true`); UI masking; optional dedicated `secrets.encryption_key` |
| Request history | Headers/bodies may contain tokens and PII | `HistorySanitizer` redacts `Authorization` / API-key headers and common secret patterns in bodies before persist; host owns retention/TTL policy |
| Import/export | JSON export may contain credentials | Restrict access; scan exports before sharing |
| XSS | Twig templates, stored endpoint names/descriptions | Twig auto-escape; do not disable escaping in overrides |
| CSRF | State-changing UI actions | CSRF tokens on execute/delete/sync endpoints |
| DoS | Large payloads, slow upstream APIs | Configurable `request_timeout_seconds` (1–300) |
| SOAP WSDL | `SoapClient` loads arbitrary WSDL URL | Same SSRF validator applied to WSDL/base URLs |

## Application responsibilities

- Configure Symfony Security (`security.yaml`) with firewall and `access_control` for the Api Studio path
- Keep `security.allow_unauthenticated: false` in production (demo-only footgun)
- Set `nowo_api_studio.security.access_roles` appropriately (never leave empty in production unless intentionally public)
- In production set `execution_url_allowlist_required: true` and a non-empty `execution_url_allowlist`
- The Flex recipe (`when@prod`) forces `execution_url_allowlist_required: true` in production; keep a non-empty allowlist.
- Prefer a dedicated `secrets.encryption_key` (env) instead of relying solely on `kernel.secret` if you rotate app secrets often
- Run `composer audit` in the application
- Do not commit `.env` or secrets; rotate env variables stored in Api Studio DB
- Redact or disable request history for sensitive environments

## Bundle responsibilities

- Block SSRF to private/local networks before outbound requests
- Enforce role checks on Api Studio routes when access roles are configured
- Encrypt `secret` environment variable values at rest when `secrets.encrypt` is true
- CSRF protection on mutating controller actions
- Validate `table_prefix` (alphanumeric + underscore only)
- Document threat model and release checklist in this file

## Residual risks (accepted for REQ-SEC-004 Pass (good) / Low)

- **Request history** is sanitized on write (`HistorySanitizer`: sensitive headers → `[REDACTED]`; password/token/Bearer patterns in bodies). Host owns retention/TTL and unusual header names.
- **Outbound HTTP** (`RequestExecutor`) logs start/finish/failure with method + host only (no Authorization / body dumps) via `LoggerInterface` (REQ-OBS-001).
- **Empty allowlist** is blocked in production by Flex `when@prod` (`execution_url_allowlist_required: true`); non-prod default remains BC-safe `false`.
- Legacy plaintext secret rows decrypt as-is until the next save re-encrypts them.

## AI security audit (REQ-SEC-004)

| Field | Value |
| --- | --- |
| Date | 2026-08-20 (re-audit; prior 2026-07-27) |
| Method | Maintainer remediation + static review (Cursor agent); recipe `when@prod` allowlist_required + HistorySanitizer + SSRF tests |
| Grade | **Pass (good)** — overall risk **Low** |
| Notes | Prod Flex forces allowlist_required; history redacted on write; residual = host retention policy |

## Release security checklist (12.4.1)

Before each release, confirm:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☑ |
| `.env` listed in `.gitignore`; no secrets in repo | ☑ |
| Flex recipe / default config contain no secrets | ☑ |
| `security.access_roles` / `ui.required_roles` default to `ROLE_ADMIN` (or documented override) | ☑ |
| SSRF validator covers REST, GraphQL, and SOAP/WSDL URLs | ☑ (regression: `ExecutionUrlValidatorTest`, `RequestExecutorTimeoutTest`) |
| User input validated (forms + Symfony validator) | ☑ |
| Output escaped (Twig templates) | ☑ |
| `composer audit` run on bundle and demo | ☑ |
| Logs/history do not dump credentials by default | ☑ |
| Secret variables encrypted at rest (`secrets.encrypt`) | ☑ |
| Permissions/exposure documented for integrators | ☑ |
| DoS limits: request timeout configured | ☑ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
