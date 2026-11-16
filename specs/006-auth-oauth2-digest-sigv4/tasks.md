---
description: "Tasks for 006 OAuth2 / Digest / SigV4 (1.18.0)"
---

# Tasks: OAuth2, Digest and AWS SigV4 auth

- [ ] T001 `AuthType` cases + form fields (service and folder forms), translations
- [ ] T002 Extract `Auth/AuthApplier` strategies from `RequestExecutor` (tests pin current output)
- [ ] T003 [P] `Auth/SigV4Signer` + official vectors
- [ ] T004 [P] `Auth/DigestAuthenticator` + RFC 7616 examples
- [ ] T005 `auth.oauth2.*` config, `ApiAuthToken` / `ApiAuthState` entities (NO PAD actor ids), conditional mapping
- [ ] T006 `OAuth2TokenProvider` (client credentials, refresh with row lock, 401 retry once)
- [ ] T007 `OAuth2Controller` start / callback / revoke (state single use, allow-list check of authorize / token URLs)
- [ ] T008 `HistorySanitizer` covers new headers; no token in logs
- [ ] T009 [P] Functional tests with mock provider; worker test two actors
- [ ] T010 Playwright PKCE flow against a mock provider in the demo
- [ ] T011 Token purge command; docs (USAGE per type), UPGRADING DDL + enum note, CHANGELOG, inventory
