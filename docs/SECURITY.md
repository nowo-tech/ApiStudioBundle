# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Application responsibilities](#application-responsibilities)
- [Bundle responsibilities](#bundle-responsibilities)
- [Browser scripts and CSP](#browser-scripts-and-csp)
- [Feature gate and 404 policy (1.16)](#feature-gate-and-404-policy-116)
- [Workspace scopes (1.17)](#workspace-scopes-117)
- [Personal data, export and retention (1.24)](#personal-data-export-and-retention-124)
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
| Secret storage | API keys/tokens in env variables persisted in DB | Variables marked `secret` are encrypted at rest with sodium (`secrets.encrypt`, default `true`; any stored value is a real payload — input that merely starts with the cipher prefix is encrypted too; a row the key cannot decrypt is loaded as stored instead of failing); optional dedicated `secrets.encryption_key` |
| Secret exposure in the UI | Decrypted secrets in pages, caches or the browser | Secret values never reach the browser: the request console gets `{{name}}` placeholders and the server resolves them on execute; execute answers mask secret values (request and echoed response); the edit form has a write-only password field (empty = unchanged); the environment page masks values; every Api Studio response is `Cache-Control: no-store, private`. **Exports (JSON / YAML / .env) contain secret values in clear** by design; every Api Studio user can still read and change every workspace (no per-workspace ACL) |
| Request history | Headers/bodies may contain tokens and PII | `HistorySanitizer` redacts `Authorization` / API-key headers and common secret patterns in bodies before persist, and masks the values of secret environment variables (raw, URL-encoded, JSON-escaped, and in the form the HTTP client normalises URL paths and queries to; 4+ characters) in the stored URL, every request and response header, the request and response bodies (upstreams that echo tokens) and, since 1.12.4, the error message (transport errors quote the URL) — also in the `/execute` answer; URLs over 2048 characters are truncated; host owns retention/TTL policy |
| Host secret source (1.13) | A host secret resolved for a request leaking to the browser, the history, logs, exports or another user; a stale or memoised decision in a worker | Opt-in `secrets.source` port; only stored values of *Secret* variables of the form `{{secret:<source>/<id>}}` are resolved, on the server, per execution (nothing memoised), after `workspace.run`; the port receives the actor, workspace, environment and tenant ids and decides; the ref carries the `targetOrigin` computed before substitution (punycode host) and the bundle refuses to send if the origin changed, if a `Host` header names another authority, or if a WSDL's `soap:address` is on another origin (422 `secret_target_changed`); a runner without `workspace.edit` cannot use a host secret in a body / header / query template they changed (422 `override_not_allowed`); the value is masked (raw, URL-encoded, JSON / `\u` escapes, HTML entities, base64 incl. Basic `user:secret`) in the execute answer (incl. the error message), the history (incl. the error message) and the bundle's log records of the execution (`SecretRedactingLogger`), never stored or exported (the reference is), absent from `ApiRequestExecutedEvent`; no redirect is followed for such a request; null / exception / under 8 bytes / malformed id (`..`, leading `/`, empty segment) fails closed (request not sent, 422 `secret_unresolved`, exception messages never logged); console overrides are never resolved; `ApiStudioSecretValue` keeps the value outside its properties (no VarDumper / `var_export` / cast exposure); SSRF and allowlist checks unchanged (they run on the resolved URL) |
| Import/export | Exports contain credentials; hostile imports | Restrict access; scan exports before sharing. The `.env` export is inert when loaded by Symfony Dotenv or a shell: values single-quoted (or double-quoted with `\`, `"`, `$`, backtick and line breaks escaped), no control character in comment lines, invalid keys skipped. Imported documents may hold at most 100,000 values (YAML alias bombs refused) |
| XSS | Twig templates, stored endpoint names/descriptions | Twig auto-escape; do not disable escaping in overrides |
| CSRF | State-changing UI actions; a host with form CSRF off would silently accept forged deletes / edits | CSRF tokens on execute/delete/sync endpoints; `ApiStudioSecurityPass` fails compilation (fail closed) when `security.csrf.token_manager` or `form.type_extension.csrf` is missing or `framework.form.csrf_protection` is false |
| DoS | Large payloads, slow upstream APIs | Configurable `request_timeout_seconds` (1–300); response bodies (REST, SOAP, raw SOAP, every WSDL / XSD document) capped by `execution_max_response_bytes` (default 10 MiB, transfer cancelled); example names / titles validated (400) |
| SOAP WSDL / endpoint | ext-soap fetching the WSDL or calling `<soap:address location>` itself would skip redirect/rebinding checks and could reach metadata or internal hosts | ext-soap never opens a connection: `GuardedWsdlLoader` downloads the WSDL and its imports through `NoPrivateNetworkHttpClient` (validator + allowlist on every document and on the final URL after redirects, `execution_max_redirects`, DTDs rejected) into temporary files; `GuardedSoapClient::__doRequest()` validates the location (SSRF + allowlist) and sends the call through the same client with the request timeout (redirect hops re-checked; an unfollowed 3xx fails the call); credentials (auth and service headers) are sent to the WSDL documents and SOAP locations of the service origin only, never to another origin named by the WSDL; raw-envelope calls (body is a full SOAP envelope) skip ext-soap and the WSDL: the target URL is validated (SSRF + allowlist) and the envelope is POSTed through the same client with the same timeout/redirect policy |

## Application responsibilities

- Configure Symfony Security (`security.yaml`) with firewall and `access_control` for the Api Studio path
- Keep `security.allow_unauthenticated: false` in production (demo-only footgun): it must be a literal boolean, needs `kernel.debug` and is refused in the `prod` environment
- List every host page of the studio in `security.host_routes` (an unlisted host route is not guarded by the bundle; a key that is not a route fails the cache warm-up) and check its workspace action in the controller with `ApiStudioAuthorization::requireWorkspace()`; a feature gate returns `true` only when the feature is on and keeps no per-request state
- Set `nowo_api_studio.security.access_roles` appropriately (an empty list denies; both role lists empty fails compilation)
- In production set `execution_url_allowlist_required: true` and a non-empty `execution_url_allowlist`
- The Flex recipe (`when@prod`) forces `execution_url_allowlist_required: true` in production; keep a non-empty allowlist.
- With `secrets.source` (1.13): authorise every reference in the port per actor, workspace and tenant, read the value per call (no cache across requests in worker mode), and never log it in the port
- Prefer a dedicated `secrets.encryption_key` (env) instead of relying solely on `kernel.secret` if you rotate app secrets often
- Run `composer audit` in the application
- Do not commit `.env` or secrets; rotate env variables stored in Api Studio DB
- Redact or disable request history for sensitive environments
- Schedule the history purge (1.24, `nowo:api-studio:history:purge` or the `PurgeApiStudioHistory` message; default
  retention 90 days) and keep the UserKit eraser / export registered (`personal_data.enabled`), or call the GDPR ports
  yourself

## Bundle responsibilities

- Block SSRF to private/local networks before outbound requests
- Enforce role checks on Api Studio routes (an empty role list denies)
- Consult the host feature gate on every Api Studio route and apply `security.access_denied_response` uniformly (1.16)
- Encrypt `secret` environment variable values at rest when `secrets.encrypt` is true
- CSRF protection on mutating controller actions
- Validate `table_prefix` (alphanumeric + underscore only)
- Document threat model and release checklist in this file
- GDPR (1.24): erase and export per actor id, byte-exact (binary column plus a PHP re-check); the export holds metadata
  only (method, URL re-masked, status, times; folder id / name / service / time), never bodies, headers or error
  messages; erasure and purge logs carry counts only (no actor id, URL or name)

## Feature gate and 404 policy (1.16)

- **Order of the checks** on every Api Studio route (bundle `nowo_api_studio_*` and the host's `security.host_routes`):
  bundle enabled (404) → tenant resolved (404) → firewall → roles and access checker → route mapped (404, deny by
  default: a route of a future version without an entry in `ApiStudioRouteActions::MAP` answers 404) → `studio.open`
  (404) → `security.feature_gate` (deny by default: only `true` passes) → `workspace.create` on the creation pages →
  the controller's workspace checks (`requireWorkspace()`: hidden 404, refused 403). The gate runs after `studio.open`
  so a person who may not open a tenant's studio cannot probe its plan — which holds only if the permission checker
  denies `studio.open` to non-members of the tenant.
- **No oracle under `access_denied_response: 404`**: refusals are replaced on `kernel.exception` (priority 16, before
  the firewall at 1 and the error renderer) by one `NotFoundHttpException('Not Found')` without headers or previous
  exception; after every response listener a 404 of a studio route gets `Cache-Control: no-store, private` and no
  `Expires` (the session listener would otherwise add `max-age=0, must-revalidate` only on the paths that read the
  session). The JSON console endpoints answer the same body as an unknown endpoint. The functional suite enumerates
  every route of the router and compares, byte for byte, the refusal with the genuine not-found of the same route
  (`tests/Functional/AccessPolicy`). Anonymous users refused by the role / access check keep the firewall's entry
  point (identical for every URL), and so do remember-me users (asked to log in again) and logins waiting for a second
  factor (scheb/2fa `TwoFactorTokenInterface`): only fully authenticated users get the 404.
- **Not covered by the policy**: invalid CSRF tokens on the JSON endpoints keep `{"error":"Invalid CSRF token."}` 403
  (checked before any lookup, so they reveal nothing about a resource); 422 validation answers; debug error pages
  (`kernel.debug` shows the original exception — never run production with debug).
- **Worker mode**: `ApiStudioAccessPolicy`, `ApiStudioRoutes` and the access subscriber are readonly and keep no
  per-request state; the gate is a host service and must not keep any either. The tenant SQL filter is turned off at
  the start and at the end of every main request.

## Browser scripts and CSP

The request console runs the endpoint **pre-request / post-response scripts** (Postman-style `pm` API) in the browser with `new Function('pm', 'console', code)` (`api-script-runtime.ts`). They are code by design:

- Only users granted `workspace.edit_scripts` (1.11; without a `security.permission_checker`, everyone who passes the Api Studio access roles) can store scripts. A stored service or endpoint script runs in the browser of whoever sends a request from that endpoint, in the page origin and with that user's session. Treat script editing as a trust grant over every user of the workspace, and map `workspace.edit_scripts` accordingly.
- **Confirmation (1.13.2, `scripts.confirm_stored`, default `true`):** before Send runs a stored script that the browser session has not approved, the console shows its code and asks to run it, to send without scripts, or to cancel. Approvals are kept in `sessionStorage` per SHA-256 of the exact code (a changed script asks again; without Web Crypto every send asks). Code the user typed in an empty script field is the user's own and is not asked. Stored scripts never run when the page is loaded, and imports (OpenAPI, Postman) do not create scripts.
- `scripts.mode: disabled` sends no script code to the browser at all; use it where scripts are not needed.
- Scripts receive only the `pm` facade (variables, request, response, a console that writes to the in-page log); they are not sandboxed in an iframe, so they can reach the page DOM.
- A strict Content-Security-Policy without `'unsafe-eval'` blocks `new Function`: on such hosts every script fails with an error that `runScriptSafe()` catches and reports in the script log. Allow `'unsafe-eval'` only on the Api Studio route prefix if you need scripts.
- Other dynamic markup (`innerHTML`) is built from static templates; user-provided values are escaped (`escapeHtmlAttr`) or written with `textContent` (REQ-UX-001).


### Sandboxed scripts (1.15, `scripts.mode: sandbox`)

**Status: re-verification pending** (spec 003, 1.15.0; the REQ-SEC-004 audit of the sandbox, the `pm.sendRequest`
relay and the server runner runs before the tag).

- The console loads `<iframe sandbox="allow-scripts">` (opaque origin: no DOM of the studio page, no cookies, no
  storage) from `nowo_api_studio_script_sandbox`, whose CSP is `default-src 'none'; script-src 'nonce-…'
  'unsafe-eval'; worker-src blob:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'`. The CSP blocks
  requests (`fetch`, XHR, WebSocket, EventSource, images, styles, scripts, frames, forms) but **not navigation**: CSP
  has no navigation directive and `allow-scripts` lets a document navigate itself, so code running in the frame
  document could send data out in a URL (`location.href = …`, an anchor click; audit RW-M1, reproduced in Chromium,
  Firefox and WebKit with and without the header). Scripts therefore never run in the frame document: the frame
  starts a dedicated **Worker** from a `blob:` URL (the only thing `worker-src blob:` allows) and runs the scripts
  there. The worker inherits the frame's policy, has no `document`, no assignable `location`, no anchors, forms or
  popups. **The CSP is the primary gate** for requests from the worker: whatever a script calls, the browser refuses
  the request. As defence in depth only, the network APIs (`fetch`, `XMLHttpRequest`, `WebSocket`, `EventSource`,
  `WebTransport`, `importScripts`, nested workers) are removed before any script runs, along the whole prototype
  chain of the worker global (in workers `fetch` and `importScripts` live on `WorkerGlobalScope.prototype`; before
  audit RW-D-L1 only an own property shadowed them and the original was recoverable from the prototype's
  descriptor), leaving a non-configurable `undefined` on the global. The frame only relays between the console and
  the worker; the console fails the run if the frame document ever loads a second time (a navigation) or reports a
  crashed worker.
- The policy is carried twice: as the response header and as `<meta http-equiv="Content-Security-Policy">`, the first
  element of the frame's `<head>`, so middleware that strips or replaces CSP headers (the dev web profiler does, a
  host CSP listener may) does not lift it; anything injected into the document later (dev toolbars) is blocked by it.
  `frame-ancestors 'self'` exists only in the header (a meta policy cannot carry it). DNS prefetching is off. Once
  the worker is started, the frame runtime also removes `fetch`, `XMLHttpRequest`, `WebSocket`, `EventSource`,
  `WebTransport`, WebRTC (`RTCPeerConnection`: no CSP directive governs it), workers, `navigator.sendBeacon` and
  `window.open` from the frame itself, along the prototype chains of `window` and `navigator` (defence in depth; no
  script runs in the frame document).
- `pm.globals` / `pm.collectionVariables` saves that deadlock or time out waiting for a lock (against another save or
  a cascade delete) are rolled back as a whole and answer 409 with `Retry-After` (audit RW-D-I1).
- The console accepts only messages from the frame it created, with origin `null` and the run's nonce; the frame
  accepts only messages from its parent. One fresh frame per phase, removed at the end or at `scripts.limits.time_ms`.
- The frame is untrusted: the console posts the run once, validates the shape of the result (strings only in every
  map, booleans and string lists where expected) and refuses one whose JSON is more than 256 KB larger than the run
  it was given, refuses `pm.sendRequest` messages over 256 KB, and removes the frame when it sends more messages than
  its `pm.sendRequest` budget plus `ready` and `result`. The response the post phase saw is never taken back from the
  frame (the console keeps its own copy).
- The studio page evaluates no script code in this mode (no `'unsafe-eval'` needed on it).
- `pm.sendRequest` goes frame → console → `nowo_api_studio_script_send_request` → the guarded HTTP client: same SSRF
  guard and allow-list as the console, no redirects, no cookies, and **nothing resolved server-side** (no variable,
  stored secret or host secret), so a stored script run by another user cannot ship that user's secrets anywhere. A
  script can still reach any public URL the allow-list permits with what it can read itself (non-secret variables,
  the response): set `execution_url_allowlist` to limit that. At most 10 calls per phase (client-side budget).
- `pm.globals` / `pm.collectionVariables` hold plain values readable by every workspace viewer (they are on the
  endpoint page): they have no secret flag and nothing secret is resolved into them; saving needs the
  environment-sync token and `workspace.edit` (and the folder's edit right), and is capped (100 changes per save, 500
  keys per scope, keys of at most 128 characters, values of at most 65,535 bytes). One save writes both scopes in one
  transaction after locking the workspace row, so a failure leaves nothing behind and two concurrent saves cannot
  both pass the per-scope cap (audit RW-L3). Writes go through DBAL one statement per key (no closed entity manager in
  worker mode).
- Server runner (`scripts.server_runner`, 1.15): the isolation boundary is the sidecar runtime. The default Deno
  command grants no permission at all (no file system, network, environment or subprocess access); the runner hides
  the runtime globals from scripts only as a convenience. The sidecar gets a scrubbed environment (no host secret),
  one process per run, killed at the time or output limit and always stopped before `run()` returns. Output is read
  in bounded chunks and input written non-blocking, so a sidecar that floods its output or never reads cannot exhaust
  PHP memory or stall the worker past the time limit. Each `pm.sendRequest` call gets only what is left of the time
  limit (`max_duration`), and the run stops as soon as that time is used up (audit RW-L2). The runner also removes
  `fetch` and the other network globals from the scripts' scope.
- **Node** (audit RW-L1): before Node 25, `--permission` does not block the network, and a script reaches it through
  `fetch` or `node:http` (a full-read SSRF: internal services, `127.0.0.1`, cloud metadata endpoints such as
  `169.254.169.254` and the credentials they hand out). The bundled runner therefore **refuses every run** under Node
  below 25, and under Node 25+ without `--permission` (or with network access granted), with the reason as the run
  error. Use the default Deno command, or Node 25+ with `--permission`. Any other custom command is the host's
  responsibility.
- `require()` (sandbox frame and server runner) returns only the bundled allow-listed libraries (see the CHANGELOG
  for the list and versions); there is no module loading, file or network access behind it.
- Secrets never reach scripts: the console hands secret variables as `{{placeholder}}`, `pm.sendRequest` sends
  placeholders literally, and responses are masked (raw, URL-encoded, JSON-escaped, HTML-escaped, base64 forms)
  before a post-response script sees them. A secret that the upstream transforms otherwise (hashed, split, encoded in
  a larger blob) is not recognised.
- Workspace and folder scripts (sandbox mode only) follow the same rules as service / endpoint scripts: their fields
  are shown and accepted only with `workspace.edit_scripts`, and they run in the same frame as the other levels.
  A Postman import stores `event[]` scripts under the same rule (sandbox mode and `workspace.edit_scripts`), never on
  an existing service or folder.
- `pm.response.headers` are the masked response headers the console already shows; `responseTime` is the measured
  round trip. Neither carries anything the page does not already have.
- Residual: secrets reach scripts only as `{{placeholders}}` (as before), and a script can still change the request
  the user sends (headers, body, variables) — that is its purpose. A script that points URL variables elsewhere is
  subject to the ER-M1 / host-secret target checks of the execute endpoint, not to the sandbox, and those checks are
  partial (audit RW-L4): ER-M1 refuses such overrides only for a runner **without** `workspace.edit`; when a user
  with `workspace.edit` sends a request whose stored script (written by someone else with `workspace.edit_scripts`)
  repoints the base URL, the stored secrets resolved into the templates go to the new host, limited only by
  `execution_url_allowlist`. Host secrets are kept to their origin only when the host's secret source binds them to
  one (`ApiStudioSecretRef::$targetOrigin`); unbound host secrets follow the URL like stored secrets. Grant
  `workspace.edit_scripts` only to people you would trust with the secrets of the workspace, and set
  `execution_url_allowlist`.
- Residual: a host that rewrites the frame document's HTML (not only its headers) can weaken the meta policy; the
  JavaScript network lockdown still applies. Exclude `<ui.path>/scripts/sandbox` from such filters.
- Residual: a hostile script can make its own phase fail (throw, loop until the time limit, flood messages); that only
  affects the send of the user running it.

## Folders (1.14)

- A host folder checker (`security.folder_permission_checker`) can only narrow the workspace decisions: hidden
  folders and their endpoints answer 404 and are left out of the tree, lists and exports; denied actions answer 403.
- Running an endpoint inside a folder with changed URL / auth variables (ER-M1) or host secrets in a changed template
  (QE-B-M2) needs `workspace.edit` on the workspace **and** on the folder (audit RJ-M4).
- Not covered by folder restrictions: environments and variables belong to the workspace and are shared by all its
  folders. So are, in sandbox mode (1.15, audit RW-I3), workspace-level pre / post scripts (they run for every
  endpoint of every folder, including folders the script's author may not edit) and `pm.globals` (one store per
  workspace, writable from a run in any folder the runner may edit). Folder auth configuration is stored and edited as plain JSON, readable by whoever may edit the folder; put
  credentials in secret variables (`{{token}}`) or host secrets, never literally.
- Imports never reuse a folder the importing user may not edit and only set auth on folders they create (RJ-M1).
  Request-level Postman credentials become endpoint headers only when they are `{{variable}}` placeholders; literal
  tokens are reported and not imported (RJ-L3).
- Tree writes lock the service row and check the rules on rows read inside the transaction; `folder.parent_id` has
  no ON DELETE action, so the database never removes a folder subtree (RJ-M2); service / workspace deletes remove their
  folders in the same flush transaction, read with SQL filters suspended (RJ-D-M1, RJ-D2-M1, RJ-D2-L2). Contention answers 409 with `Retry-After` (RJ-L1); imports into
  a service take the same lock (RJ-D-L1).
- The re-read after the lock needs a fresh view: PostgreSQL READ COMMITTED (default) takes one per statement, and
  MySQL / MariaDB REPEATABLE READ (default) takes its snapshot at the first plain read, which comes after the lock.
  A host that forces REPEATABLE READ on PostgreSQL makes every read use the snapshot of the transaction's first
  statement (the lock, taken before the wait), so the rules may be checked against stale rows. Keep the default
  isolation level for the Api Studio connection on PostgreSQL (RJ-D-L4).

## Workspace scopes (1.17)

- **Off by default.** Without `scope.enabled` no column is mapped, no twin route is loaded, and the resolver and the
  checker are never called.
- **The scope is part of every workspace lookup.** `ApiStudioAuthorization::requireWorkspace()` goes through
  `ApiStudioScope::contains()`, which compares the workspace's scope with the request's byte-exact (`===`); lists,
  counts and the sidebar filter `host_scope` in the query and re-check every row in PHP; the tenancy SQL filter narrows
  by scope too (defence in depth). A workspace of project B opened under project A's prefix, a tenant-level workspace
  under a project prefix, or the same scope value of another tenant answers the same 404 as a missing id (no IDOR, no
  oracle).
- **Exact matching** (REQ-SEC-006, LQ rule): `WorkspaceScope` refuses anything outside `^[A-Za-z0-9._:-]{1,128}$`
  (no trimming or case folding), `host_scope` has a binary NO PAD collation on MySQL / MariaDB, PostgreSQL and SQLite
  compare exactly; tested on all three servers.
- **Deny by default.** `scope.access_checker` is mandatory (the build fails without it); every action in a scope needs
  it **and** the permission checker; `studio.open` / `workspace.view` denied → 404; unknown actions must be denied; an
  exception fails the request. Tenant-level lists show only the scopes `visibleScopes()` returns (none without a
  checker).
- **The scope comes from the route, never from the client**: the resolver is the host's (identify only, it must check
  that the route value belongs to the tenant); forms have no scope field.
- **Host secrets per scope** (audit UA-M2): every `ApiStudioSecretRef` carries the workspace's `scope` (null at tenant
  level). A host secret source must authorise `source/id` against the tenant **and** the scope: an editor can copy a
  `{{secret:…}}` reference into a workspace of another project, and only the source can refuse to resolve it there.
- **Slug validation per scope** (audit UA-M1): the "slug taken" check of the forms is narrowed to (tenant, scope), so it
  neither refuses a slug used only in another scope nor reveals another scope's slugs (with or without the SQL filter).
- **Seed API**: no secret value can be expressed (plain variables refuse `{{secret:…}}`, secrets are references only,
  refused without `secrets.source`; auth, headers, bodies and scripts are not seeded); the seeder logs ids, counts and
  attempts only, and its validation messages never carry a value. It is a PHP service, not an endpoint: the caller
  authorises and chooses the tenant and the scope.
- Worker mode: the scope lives on the main request (always written, null included), decisions are memoised on the
  request, `ScopeContext::runAs()` is restored in `finally` and by `reset()`, router-context placeholders are restored
  at `kernel.finish_request` and by `reset()`.

## Personal data, export and retention (1.24)

| Item | Detail |
| --- | --- |
| What is personal | `executed_by` (opaque actor id), request / response URL, headers and bodies and the error message of each history row (`history.scope: user`); `created_by` of folders. |
| Erasure | `ApiStudioPersonalDataEraserInterface::erase()`: history rows deleted (bodies with them), folders anonymised (kept, `created_by` null). The UserKit eraser erases the ids of the original and the current identifier. |
| Export | Metadata only: `history` (id, endpoint id, method, URL masked again by `HistorySanitizer::sanitizeUrl()`: secret-like query and fragment names, the userinfo password or a user name alone; status, time) and `folders_created` (id, name, service id, time). Bodies, headers and error messages are never exported. |
| Look-alike ids | The actor column has a binary NO PAD collation on MySQL / MariaDB (1.24 for `executed_by`, 1.14 for `created_by`); every row read for erasure or export is re-checked with `===` in PHP, so an install that has not migrated yet still never reaches `u:UserA` for `u:usera` or `u:UserA ` (trailing space). Ids are what the bundle stores (the default id is `u:` + the raw identifier, so spaces and non-ASCII are legitimate): valid UTF-8, 1–128 characters, no NUL (control characters of an imported identifier, such as `u:ana\n`, are accepted); anything else is refused by the ports. The UserKit bridges log (class only) and skip both a host resolver id of the wrong shape and an id the ports refuse: no row can hold them, and UserKit's erasure / export is never aborted. |
| Resolver and erasure order | A host `actor_resolver` must read an immutable key: a host identity eraser with a priority above 150 that anonymises the field it reads makes the `api_studio` eraser miss the rows. |
| Atomicity | Same connection as UserKit: savepoints of its transaction. Another connection: each page commits on its own before UserKit commits (idempotent; a retry completes it). |
| Logs | Erasure / export: counts only. Purge: cut-off, batch size, counts. A batch retry logs the attempt and the exception class. Never actor ids, URLs, folder names or SQL. |
| Retention | `history.retention_days` (default 90; 0 = keep). The purge runs only when scheduled; it deletes by primary key, oldest first, in short transactions, refuses to run inside an open transaction. |
| Residual | The URL is masked by parameter names and userinfo; a secret placed in a path segment or a non-secret-looking parameter name of an older row is exported as stored (it was masked at write time with the then known secret values). Rows stored while `history.scope` was `endpoint` carry no actor and cannot be attributed (they go with the retention). |

## Residual risks (accepted for REQ-SEC-004 Pass (good) / Low)

- **Request history** is sanitized on write (`HistorySanitizer`: sensitive headers → `[REDACTED]`; password/token/Bearer patterns in bodies). Host owns retention/TTL and unusual header names.
- **Outbound HTTP** (`RequestExecutor`) logs start/finish/failure with the method and the scheme + host only (no path, query, Authorization or body); a failure adds the duration and the exception **class**, never its message, which quotes the full URL (1.12.4, QE-A-M1) — via `LoggerInterface` (REQ-OBS-001). The framework `http_client` service logs on its own `http_client` channel (at INFO, the full URL): raise that channel to WARNING where secrets travel in URLs.
- **Empty allowlist** is blocked in production by Flex `when@prod` (`execution_url_allowlist_required: true`); non-prod default remains BC-safe `false`.
- Legacy plaintext secret rows decrypt as-is until the next save re-encrypts them.
- **Host secrets** (1.13, `secrets.source`, audit QE):
  - The target may echo the value in forms the bundle does not recognise (hashed, truncated, split, compressed); raw,
    URL-encoded, JSON / `\u`, HTML-entity and base64 forms are masked (QE-B-L1). A browser script sees what the
    target answers after masking.
  - A runner with `workspace.edit` can point the service at another host: the port must bind each secret to the origins
    it may go to (`ApiStudioSecretRef::$targetOrigin`, QE-B-M1); per-actor grants alone do not stop a confused deputy.
  - The framework `http_client` service logs the full URL on its `http_client` channel (INFO) and the profiler keeps
    URLs, headers and bodies in debug (QE-B-L2, pre-existing for stored secrets): raise that channel to WARNING and
    keep the profiler off where host secrets can travel in URLs.
  - Redirects are not followed for a request that carries a host secret (QE-B-L4). With `execution_max_redirects > 0`,
    other requests still forward custom headers (API-key auth, stored secrets) and 307 / 308 bodies to another origin
    (Symfony strips only `Authorization` and `Cookie`): keep it at 0 when that matters.
- **Secrets the configured key cannot decrypt fail closed** (1.10.1): flagged *Needs re-entry*, never sent (a request
  that references one is not sent, 422 `secret_unreadable`), never exported (`null`), never re-saved as ciphertext.
  `nowo:api-studio:secrets:status` lists their ids. Changing `secrets.encryption_key` / `kernel.secret` without a
  rotation makes every stored secret unreadable this way.
- **Stored payloads are sensitive** (Appendix EQ, EQ-L1, Low, pre-existing): a v1 payload is not bound to its row. Since
  1.11 every typed value is encrypted afresh (a pasted payload is stored as text, never as a payload), but anyone who
  can read the database or a backup must still be treated as able to recover secrets with the key. Binding payloads to
  their row (AEAD associated data) is part of the v2 format planned for 1.12.0.
- **What `run`, `edit` and `import` mean for secrets** (1.11, Appendix ER, ER-M1 / ER-I5): `workspace.run` sends the
  workspace's requests **as configured**: a runner without `workspace.edit` may not change any variable used in the base
  URL, the path, the endpoint query parameters, the auth configuration or a header that carries a secret (`/execute`
  answers 422 `override_not_allowed` and logs ids only), so the stored secrets only ever go to the configured origin.
  `workspace.edit` and `workspace.import` are trust grants over the secrets in use: an editor can point a service at
  another host or untick *Secret* on a readable secret, and an importer can create services with any base URL. Map
  them no lower than the people you would trust with the values; `workspace.export_variables` hands the values out.
- **Per-workspace access** (1.11): with `security.permission_checker` a host restricts who may view, run, edit, import or export each workspace (variable exports hold secret values in clear: `workspace.export_variables`), and with `tenancy` each tenant only sees its own workspaces (404 otherwise). Without them:
- **Secrets are readable by every Api Studio user** (no per-workspace ACL): through exports (clear values, by design), by sending a request to a host they control, or by a browser script reading `pm.environment` after a request (scripts get the placeholder, not the value, but can exfiltrate what the upstream answers). Encryption protects the database at rest, not the UI.
- In `scripts.mode: inline` (the 1.x default, deprecated since 1.15) browser scripts (`new Function`) run unsandboxed
  in the Api Studio origin (see above). Since 1.13.2 a stored script runs only after the sending user approved its code
  in the confirmation dialog (`scripts.confirm_stored`), but an approved script still runs with that user's session:
  the confirmation relies on the user reading the code. Since 1.15 `scripts.mode: sandbox` runs every script in the
  opaque-origin frame instead (and `scripts.server_runner` outside the browser); use it, or `scripts.mode: disabled`
  where scripts are not needed.
- 1.17 workspace scopes: host templates that override bundle templates and keep `path('nowo_api_studio_…')` link
  from a scoped page to the tenant-level studio (the link is still guarded there; switch to `api_studio_path()`).
- 1.17 (audit UA-L1, fixed): before 1.17 the tenant columns of 1.11 (`tenant_type`, `tenant_id`) had no binary
  collation, so on MySQL / MariaDB lists, counts and the sidebar of tenant `ACME` could include rows of tenant `acme`
  (lookups by id were already exact in PHP), and the unique key made their slugs collide. Since 1.17 the columns get a
  binary NO PAD collation (migration in [UPGRADING](UPGRADING.md#to-1170)), lists and the sidebar re-check the tenant
  in PHP, and `ApiStudioTenant` refuses a trailing newline (`/D`). Hosts that skip the column migration keep exact
  lists and lookups, but counts and the unique key stay case-insensitive until they migrate.
## AI security audit (REQ-SEC-004)

| Field | Value |
| --- | --- |
| Date | 2026-10-30 (Appendix BW, `main` `06a70f5` → 1.10.0; prior 2026-08-20, 2026-07-27) |
| Method | Independent Claude Code static review + PoCs (Symfony Dotenv parse, env-placeholder container build, YAML alias expansion), fixes in `fix/bw-audit` |
| Grade | **Pass (conditional) / Medium** at `06a70f5`; with the BW fixes **Pass (good) / Low** expected |
| Fixed | M1 `.env` export command execution / line injection (affects 1.0.0–1.9.2, GHSA); L1 secrets sent to the browser; L2 unbounded responses; L3 `allow_unauthenticated` env placeholder / prod; L4 cipher-prefix values stored in clear and 500 on load; L5 SOAP credentials to another origin; L6 response-side history masking; I2 server-side typed name for workspace deletes; I4 VARCHAR overflows; I5 YAML alias bomb; I8 UiKit / FormKit floors |
| Residual | Secrets readable by every Api Studio user (exports, scripts, requests to own hosts); unsandboxed browser scripts in `inline` mode (I1; stored scripts need the sending user's approval since 1.13.2, `scripts.confirm_stored`; `sandbox` mode since 1.15); empty allowlist outside prod; no history retention command (host owns TTL); demo e2e `sharp` dev dependency advisory (I7, dev only) |

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
