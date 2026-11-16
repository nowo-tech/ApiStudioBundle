# Feature Specification: Later backlog

**Feature Branch**: `011-later-backlog`
**Created**: 2026-11-16
**Status**: Backlog (not scheduled)
**Target version**: 1.23.0+ (each item its own minor once specified in detail); anything that needs a BC break → 2.0
**Roadmap item**: 10 of 10
**Input**: "Later: WebSocket/SSE (SSRF check on the handshake), gRPC, a visual OpenAPI editor with components,
endpoint comments, and AI test/example generation through the host's AI provider."

Each item below gets its own numbered spec (012+) with full user stories before work starts. This file records scope,
constraints already decided and open questions.

## User Scenarios *(summary)*

### B1 - WebSocket and SSE (Priority: P1 of the backlog)

Connect to `ws://` / `wss://` and SSE endpoints from the console, send / receive messages, save message examples.

- The connection is made **server-side** (a relay over the studio session, e.g. Mercure or a streamed response) or
  browser-side; either way the target URL passes `ExecutionUrlValidator` (SSRF guard + allow-list) **on the handshake
  and on every redirect**, DNS is pinned for the connection, private ranges are refused.
- Browser-side connections cannot be SSRF-guarded by the server; therefore browser-side mode is allowed only for URLs
  that pass the same validator before the console opens them, and it is off by default.
- Limits: message size, connection duration, messages per connection; worker mode: relays never block a worker for
  longer than the limit (host must run them in a dedicated pool).

### B2 - gRPC (P2)

Proto import (file upload, server reflection through the guarded client), unary calls first; streaming later. Needs
`ext-grpc` or a pure-PHP HTTP/2 client: decision pending (open question Q-B2).

### B3 - Visual OpenAPI editor with components (P2)

Edit services as OpenAPI 3.1: schemas / parameters / responses as reusable components, `$ref` aware, validation,
diff against the last import. Persisted as JSON in a new `api_openapi_document` table per service; endpoints stay the
executable view. Heavy front end: evaluate an embedded editor vs. own forms (open question Q-B3).

### B4 - Endpoint comments (P3)

Threaded comments per endpoint / folder with mentions, using the host's notification port (NotifyKit when present).
Actor ids stored with binary NO PAD collation; GDPR export / erase hooks.

### B5 - AI test and example generation (P3)

Generate assertions (spec 004), request examples and descriptions through the **host's** AI provider port
(`ApiStudioAiProviderInterface`, no bundle-side keys). Prompts never include secret values or environment values;
output is a draft the user accepts. Off unless the host wires a provider.

## Cross-cutting constraints

- Every new outbound path (WS / SSE handshakes, gRPC, reflection, AI provider calls made by the bundle, mock proxy
  fallback) goes through the SSRF guard and the execution allow-list.
- FrankenPHP worker mode with kernel reset false: no per-request state in services; long-lived connections are not run
  inside request workers.
- Opt-in configuration for each item; schema changes mapped only when enabled.

## Open questions

- **Q-B1**: WS relay transport (Mercure vs. streamed response vs. separate worker).
- **Q-B2**: gRPC client stack.
- **Q-B3**: OpenAPI editor build vs. embed.
- **Q-B5**: AI provider port shape shared with other bundles (Beacon).

## Success Criteria

- Each backlog item reaches a detailed spec (012+) approved by the owner before implementation.
