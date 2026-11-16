# Feature Specification: OAuth2, Digest and AWS SigV4 auth

**Feature Branch**: `006-auth-oauth2-digest-sigv4`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.18.0 (minor)
**Roadmap item**: 5 of 10
**Input**: "OAuth2: client credentials, authorization code with PKCE, refresh. Tokens are stored as secrets and
refreshed server-side through the guarded client. Digest. AWS SigV4."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - OAuth2 client credentials (Priority: P1)

An editor configures a service or folder with OAuth2 client credentials (token URL, client id, client secret as a
secret variable reference, scope, audience, client auth `basic` / `body`). Sending a request obtains a token
server-side, caches it as an encrypted secret until expiry and adds `Authorization: Bearer`.

**Independent Test**: mocked token endpoint returns `expires_in: 60`; two requests reuse the token; after expiry a new
one is fetched; the token never appears in the browser, history or logs.

**Acceptance Scenarios**:

1. **Given** client credentials auth, **When** a request is sent, **Then** the token request goes through the guarded
   client (SSRF guard, allow-list, no redirects, response size limit) and the token is stored encrypted (`SecretValueCipher`).
2. **Given** a 401 from the API with a cached token, **Then** the token is refreshed once and the request retried once.
3. **Given** the token URL outside the allow-list, **Then** the request fails closed with a clear error code.

### User Story 2 - Authorization code with PKCE (Priority: P1)

A user authorises interactively: the console opens the provider's authorize URL (S256 PKCE, `state`), the provider
redirects to the bundle callback route, the bundle exchanges the code server-side and stores access / refresh tokens as
secrets for that user (per actor, per environment).

**Acceptance Scenarios**:

1. **Given** the callback with a wrong / expired / reused `state`, **Then** it is refused (state single-use, 10 minutes,
   bound to the user and the service).
2. **Given** stored tokens, **Then** they belong to the authorising actor; other users must authorise themselves
   (per-actor tokens, actor id compared exactly, NO PAD collation).
3. **Given** a refresh token, **Then** expired access tokens are refreshed server-side through the guarded client; a
   failed refresh marks the token "needs re-authorisation".
4. **Given** an attacker-chosen `redirect_uri`, **Then** impossible: the redirect URI is the bundle route, absolute,
   computed server-side, and the authorize URL host must pass the allow-list.

### User Story 3 - Digest (Priority: P2)

HTTP Digest (RFC 7616: MD5, SHA-256, `qop=auth`) with the challenge / response round trip handled server-side.

**Acceptance Scenarios**: a mocked server with a Digest challenge accepts the second request; credentials come from
(secret) variables; the challenge round trip counts as one execution in history.

### User Story 4 - AWS Signature V4 (Priority: P2)

Access key id, secret access key (secret variable), optional session token, region, service; the request is signed
server-side after variable resolution (canonical request over the final URL, headers and body hash).

**Acceptance Scenarios**: the AWS SigV4 test-suite vectors (get-vanilla, post-x-www-form-urlencoded, ...) produce the
documented signatures (unit tests).

### Edge Cases

- Tokens of a deleted environment / service are deleted (cascade).
- Changing the token URL or client id invalidates cached tokens (config fingerprint stored with the token).
- Runner / monitors (specs 004 / 005) use client credentials; authorization code tokens only when the monitor's actor
  has authorised.

## Requirements *(mandatory)*

- **FR-AUTH-001**: `AuthType` gains `oauth2`, `digest`, `aws_sigv4` (additive enum cases).
- **FR-AUTH-002**: Entity `ApiAuthToken` (service or folder, environment, grant type, actor id, encrypted access /
  refresh token, expiry, config fingerprint).
- **FR-AUTH-003**: `Auth/OAuth2TokenProvider` (client credentials, code exchange, refresh) through the guarded client;
  `Auth/PkceState` storage (DB row, single use, TTL).
- **FR-AUTH-004**: Callback route `<ui prefix>/oauth2/callback` (internal UI route, CSRF via `state`).
- **FR-AUTH-005**: `Auth/DigestAuthenticator`, `Auth/SigV4Signer` (pure, unit-tested against vectors).
- **FR-AUTH-006**: Tokens and secrets never reach the browser, history, logs or exports (`HistorySanitizer` masks the
  `Authorization` header and SigV4 `X-Amz-Security-Token`).
- **FR-AUTH-007**: Command `nowo:api-studio:tokens:purge` (expired, orphaned).

## Success Criteria *(mandatory)*

- **SC-001**: SigV4 official vectors pass; Digest RFC 7616 examples pass.
- **SC-002**: PKCE flow e2e against a mock provider in the demo.
- **SC-003**: No token in history / logs (tests with an echoing mock).
