# Feature Specification: Mocks from saved examples

**Feature Branch**: `009-mocks-from-examples`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.21.0 (minor, opt-in)
**Roadmap item**: 8 of 10
**Input**: "Mocks from saved examples. Opt-in, with a per-tenant URL and rate limiting."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Serve saved response examples (Priority: P1)

An editor enables a mock for a service; the bundle serves its saved response examples at a per-tenant mock URL:
`<prefix>/api-studio-mock/v1/{mockKey}/{path}` where `mockKey` is a random 32-character token per service (rotatable).

**Independent Test**: endpoint `GET /users/{id}` with a 200 example; `GET …/v1/{key}/users/42` answers the example
body, status and headers.

**Acceptance Scenarios**:

1. **Given** several examples for one endpoint, **Then** the selection is: `x-mock-response-name` header →
   `x-mock-response-code` header → the example marked default → the first 2xx.
2. **Given** path templates (`/users/{id}`), **Then** matching is by method + template; the most specific template wins.
3. **Given** no match, **Then** 404 with a JSON problem body listing nothing about other services.
4. **Given** `mocks.enabled: false` (default) or the service's mock off, **Then** 404 for every mock URL.

### User Story 2 - Isolation and rate limiting (Priority: P1)

**Acceptance Scenarios**:

1. **Given** tenancy, **Then** a mock key resolves only within its tenant's workspaces (the key is unique; lookups go
   through the tenant-aware repository) and a rotated key stops working immediately.
2. **Given** `mocks.rate_limit` (default 60 requests per minute per key and per client IP, sliding window via
   `symfony/rate-limiter`), **Then** excess requests get 429 with `Retry-After`.
3. **Given** a mock response, **Then** it never contains secret values (examples are stored masked by the history
   sanitiser; the mock re-applies masking of secret variables of the workspace before serving).
4. **Given** CORS, **Then** the mock answers with `mocks.cors_allowed_origins` (default none) only.

### User Story 3 - Dynamic values (Priority: P3)

Optional templating in example bodies: `{{$randomUUID}}`, `{{$timestamp}}`, path parameters `{{path.id}}`. No
expression language, no access to variables or secrets.

## Requirements *(mandatory)*

- **FR-MOCK-001**: Config `mocks.enabled` (false), `mocks.path_prefix` (`/api-studio-mock`, host-configurable per the
  public API path rule), `mocks.rate_limit` (`60/minute`), `mocks.cors_allowed_origins` ([]).
- **FR-MOCK-002**: `ApiService` mock settings (enabled, key hash, key prefix for display) — key stored hashed
  (SHA-256), shown once on creation / rotation.
- **FR-MOCK-003**: Public route `<prefix>/api-studio-mock/v1/{mockKey}/{path}` (versioned, OpenAPI document for the mock
  surface), outside the studio firewall; no session, no cookies set.
- **FR-MOCK-004**: No outbound request is ever made by the mock (no proxy-to-real mode in this spec; proxy fallback
  would have to go through the SSRF guard and is listed in spec 011).
- **FR-MOCK-005**: Response example gains `isDefault` (bool) and optional headers map.

## Success Criteria *(mandatory)*

- **SC-001**: Two tenants with the same path never see each other's examples (functional test).
- **SC-002**: 61st request within a minute answers 429 (functional test with an in-memory limiter).
- **SC-003**: Worker test: 5,000 mock requests across two keys, flat memory, limiter storage outside services.
