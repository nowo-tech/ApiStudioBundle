# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Bundle responsibilities](#bundle-responsibilities)
- [Browser scripts and CSP](#browser-scripts-and-csp)
- [Residual risks (accepted for REQ-SEC-004 Pass (good) / Low)](#residual-risks-accepted-for-req-sec-004-pass-good--low)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Scope

Api Studio Bundle provides a **web UI and persistence layer** for designing, documenting, and executing HTTP/SOAP/GraphQL API requests against configured environments.

The bundle **does not replace** Symfony Security. Authentication, firewall rules, and network perimeter controls remain the responsibility of the host application.

## Attack surface

| Input / surface | Description |
| --- | --- |
| HTTP UI | Dashboard, workspaces, services, endpoints, environment variables, import/export, request execution |
| Outbound HTTP | REST/GraphQL via Symfony HttpClient; SOAP raw envelopes POSTed directly through the guarded HttpClient, or SOAP via ext-soap (`GuardedSoapClient`) with WSDL download and SOAP calls routed through the same guarded HttpClient |
| Database | Workspaces, services, endpoints, env variables (including `secret` flag), request history |
| CLI | Schema sync, demo seed (operator-controlled) |
| Configuration | `security.access_roles`, `execution_url_allowlist`, `secrets.encrypt`, request timeout |

## Threat model

| Area | Risk | Mitigation |
| --- | --- | --- |
| Unauthorized UI access | Unauthenticated users execute requests or read secrets | `security.access_roles` (default `ROLE_ADMIN`) and optional `security.access_checker` (`canAccess($user)`), both enforced by `ApiStudioAccessSubscriber`; `allow_unauthenticated: false`; host `access_control` on `ui.path`. `enabled: false` makes all routes 404 (never fail-open). An empty effective role list denies, and `ApiStudioSecurityPass` refuses to compile it unless `access_checker` / `allow_unauthenticated` is set. |
| Cross-workspace secrets | Executing an endpoint with another workspace's environment | Execute endpoint rejects `environment_id` outside the endpoint's workspace (400) |
| Open redirect | Locale switch following a forged `Referer` | Only same-host/local `Referer` is followed |
| SSRF | Authenticated user targets internal services (metadata, Redis, admin panels) | `ExecutionUrlValidator` pre-flight blocks `IpUtils::PRIVATE_SUBNETS` + multicast for every A/AAAA record and fails closed on unresolvable hosts; HTTP calls go through `NoPrivateNetworkHttpClient` (connected IP re-checked: DNS rebinding); redirects off by default (`execution_max_redirects`); host-exact `execution_url_allowlist` + optional `execution_url_allowlist_required` |
| Secret storage | API keys/tokens in env variables persisted in DB | Variables marked `secret` are encrypted at rest with sodium (`secrets.encrypt`, default `true`); UI masking; optional dedicated `secrets.encryption_key` |
| Request history | Headers/bodies may contain tokens and PII | `HistorySanitizer` redacts `Authorization` / API-key headers and common secret patterns in bodies before persist, and masks the values of secret environment variables (raw, URL-encoded, JSON-escaped; 4+ characters) in the stored URL, every request header and the request body; host owns retention/TTL policy |
| Import/export | JSON export may contain credentials | Restrict access; scan exports before sharing |
| XSS | Twig templates, stored endpoint names/descriptions | Twig auto-escape; do not disable escaping in overrides |
| CSRF | State-changing UI actions; a host with form CSRF off would silently accept forged deletes / edits | CSRF tokens on execute/delete/sync endpoints; `ApiStudioSecurityPass` fails compilation (fail closed) when `security.csrf.token_manager` or `form.type_extension.csrf` is missing or `framework.form.csrf_protection` is false |
| DoS | Large payloads, slow upstream APIs | Configurable `request_timeout_seconds` (1–300) |
| SOAP WSDL / endpoint | ext-soap fetching the WSDL or calling `<soap:address location>` itself would skip redirect/rebinding checks and could reach metadata or internal hosts | ext-soap never opens a connection: `GuardedWsdlLoader` downloads the WSDL and its imports through `NoPrivateNetworkHttpClient` (validator + allowlist on every document and on the final URL after redirects, `execution_max_redirects`, DTDs rejected) into temporary files; `GuardedSoapClient::__doRequest()` validates the location (SSRF + allowlist) and sends the call through the same client with the request timeout (redirect hops re-checked; an unfollowed 3xx fails the call); raw-envelope calls (body is a full SOAP envelope) skip ext-soap and the WSDL: the target URL is validated (SSRF + allowlist) and the envelope is POSTed through the same client with the same timeout/redirect policy |

## Application responsibilities

- Configure Symfony Security (`security.yaml`) with firewall and `access_control` for the Api Studio path
- Keep `security.allow_unauthenticated: false` in production (demo-only footgun)
- Set `nowo_api_studio.security.access_roles` appropriately (an empty list denies; both role lists empty fails compilation)
- In production set `execution_url_allowlist_required: true` and a non-empty `execution_url_allowlist`
- The Flex recipe (`when@prod`) forces `execution_url_allowlist_required: true` in production; keep a non-empty allowlist.
- Prefer a dedicated `secrets.encryption_key` (env) instead of relying solely on `kernel.secret` if you rotate app secrets often
- Run `composer audit` in the application
- Do not commit `.env` or secrets; rotate env variables stored in Api Studio DB
- Redact or disable request history for sensitive environments

## Bundle responsibilities

- Block SSRF to private/local networks before outbound requests
- Enforce role checks on Api Studio routes (an empty role list denies)
- Encrypt `secret` environment variable values at rest when `secrets.encrypt` is true
- CSRF protection on mutating controller actions
- Validate `table_prefix` (alphanumeric + underscore only)
- Document threat model and release checklist in this file

## Browser scripts and CSP

The request console runs the endpoint **pre-request / post-response scripts** (Postman-style `pm` API) in the browser with `new Function('pm', 'console', code)` (`api-script-runtime.ts`). They are code by design:

- Only users who can edit endpoints (Api Studio access roles) can store scripts; they run in the browser of whoever opens the endpoint, with that user's session. Treat endpoint editing as trusted, like editing a Postman collection, and grant `access_roles` accordingly.
- Scripts receive only the `pm` facade (variables, request, response, a console that writes to the in-page log); they are not sandboxed in an iframe, so they can reach the page DOM.
- A strict Content-Security-Policy without `'unsafe-eval'` blocks `new Function`: on such hosts every script fails with an error that `runScriptSafe()` catches and reports in the script log. Allow `'unsafe-eval'` only on the Api Studio route prefix if you need scripts.
- Other dynamic markup (`innerHTML`) is built from static templates; user-provided values are escaped (`escapeHtmlAttr`) or written with `textContent` (REQ-UX-001).

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
| SSRF validator covers REST, GraphQL, and SOAP/WSDL URLs (WSDL imports and SOAP endpoint location included) | ☑ (regression: `ExecutionUrlValidatorTest`, `RequestExecutorTimeoutTest`, `GuardedSoapExecutionTest`, `RawSoapEnvelopeExecutionTest`, incl. SOAP-call redirects to private addresses) |
| User input validated (forms + Symfony validator) | ☑ |
| Output escaped (Twig templates) | ☑ |
| `composer audit` run on bundle and demo | ☑ |
| Logs/history do not dump credentials by default | ☑ |
| Secret variables encrypted at rest (`secrets.encrypt`) | ☑ |
| Permissions/exposure documented for integrators | ☑ |
| DoS limits: request timeout configured | ☑ |

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for private disclosure.
