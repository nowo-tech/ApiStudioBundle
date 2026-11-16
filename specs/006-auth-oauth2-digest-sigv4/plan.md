# Implementation Plan: OAuth2, Digest and AWS SigV4 auth

**Branch**: `006-auth-oauth2-digest-sigv4` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.18.0** (minor)

## Summary

Three new auth types resolved server-side in `RequestExecutor` (and inherited through folders, spec 002). OAuth2
tokens are cached as encrypted secrets per service / folder, environment and (for authorization code) actor, and are
refreshed through the SSRF-guarded client.

## Configuration

```yaml
nowo_api_studio:
    auth:
        oauth2:
            enabled: false             # maps the token / state tables
            state_ttl: PT10M
            refresh_skew_seconds: 30
```

Digest and SigV4 need no configuration and no table.

## Data model and migrations

| Table | Columns |
| --- | --- |
| `{prefix}auth_token` | `id`, `service_id` FK cascade, `folder_id` FK nullable cascade, `environment_id` FK nullable cascade, `grant_type` varchar(32), `actor_id` varchar(128) nullable **binary NO PAD collation**, `access_token` text (encrypted payload), `refresh_token` text nullable (encrypted), `expires_at` datetime nullable, `config_fingerprint` char(64), `status` varchar(16), `updated_at` |
| `{prefix}auth_state` | `id`, `state_hash` char(64) unique, `actor_id` varchar(128) **binary NO PAD collation**, `service_id`, `folder_id` nullable, `environment_id` nullable, `code_verifier` text (encrypted), `expires_at` |

Mapped only with `auth.oauth2.enabled`. `auth_config` JSON on service / folder carries the non-secret settings and the
names of the secret variables (never the values).

## Architecture

- `AuthType` new cases; forms show their fields (FormKit fieldsets); the console auth hint lists the type.
- `Auth/AuthApplier` extracted from `RequestExecutor::applyAuthHeaders()` (strategy per type, stateless).
- `Auth/OAuth2TokenProvider`: token fetch / refresh via `RequestExecutor`'s guarded client; DB row lock on refresh
  (`SELECT … FOR UPDATE` on the token row) so parallel workers do not double-refresh.
- `Controller/OAuth2Controller`: start (redirect to authorize URL after allow-list check), callback (state check,
  exchange, store), revoke.
- `Auth/DigestAuthenticator`: challenge parse + response (second request within the same execution).
- `Auth/SigV4Signer`: canonical request / string to sign / signature; signs after variables are resolved.

## BC analysis

| Change | Impact | Mitigation |
| --- | --- | --- |
| New `AuthType` cases | Hosts with exhaustive `match` on `AuthType` get an unhandled case | Documented in UPGRADING; enum is a bundle value type, adding cases in a minor is allowed by the BC policy for enums used as data |
| `RequestExecutor` internals | Internal class | Behaviour identical for existing types (tests pin it) |
| New tables | Opt-in | Mapped only with `auth.oauth2.enabled` |

No BC break.

## FrankenPHP worker mode (kernel reset false)

- Token provider, signers and authenticators keep no token in properties; tokens are read per call from the DB and
  decrypted into local variables only.
- PKCE state lives in the DB, never in services or the PHP session store of a worker-global object.
- Worker test: 1,000 executions alternating two actors, no token of actor A ever used for actor B, flat memory.
