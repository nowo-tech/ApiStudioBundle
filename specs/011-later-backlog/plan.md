# Implementation Plan: Later backlog

**Branch**: `011-later-backlog` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: 1.23.0 and later minors, one per item once specified; BC-breaking parts → 2.0

## Sequencing proposal

| Item | Earliest | Depends on | Notes |
| --- | --- | --- | --- |
| B1 WebSocket / SSE | 1.23.0 | 003 (scripts on messages, optional) | Server relay with SSRF check on handshake + redirects |
| B4 Endpoint comments | 1.24.0 | 002 (folders) | Host notification port |
| B5 AI generation | 1.25.0 | 004 (assertions) | Host AI provider port only |
| B3 Visual OpenAPI editor | 1.26.0+ | 007 (export / import) | Large UI scope |
| B2 gRPC | 2.x | — | Client stack decision; may need PHP extensions |

## Data model and migrations (anticipated, all opt-in)

- B1: `{prefix}message_example` (endpoint, direction, payload, created_at).
- B3: `{prefix}openapi_document` (service, document json, version, updated_at).
- B4: `{prefix}comment` (endpoint / folder, parent, author_id varchar(128) binary NO PAD, body, created_at, edited_at).
- B5: none (drafts are not persisted until accepted).

## BC analysis

Every item is additive and opt-in. Removing `scripts.mode: inline` and defaulting `folders.enabled` /
`scripts.mode: sandbox` belong to **2.0**, not to this backlog.

## FrankenPHP worker mode (kernel reset false)

Long-lived connections (B1) must not run in request workers; relays run in a dedicated process / pool with limits.
All other items follow the stateless-service rules of specs 002–010.
