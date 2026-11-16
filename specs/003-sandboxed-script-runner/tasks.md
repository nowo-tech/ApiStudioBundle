---
description: "Tasks for 003 sandboxed script runner (1.15.0)"
---

# Tasks: Sandboxed script runner

**Input**: [spec.md](spec.md), [plan.md](plan.md)

## Phase 1: Setup

- [ ] T001 `scripts.mode: sandbox`, `scripts.limits.*`, `scripts.server_runner`, `scripts.process_runner.binary` in
  `Configuration.php`; deprecation notice for `inline`
- [ ] T002 [P] Vite entry `api-script-sandbox` (pm runtime + allow-listed libraries, pinned versions)

## Phase 2: Foundational

- [ ] T003 `ScriptSandboxController` + static frame document with its own CSP; route action `studio.open`
- [ ] T004 Message protocol types (`types/global.d.ts`), nonce, size and count bounds
- [ ] T005 Runner abstraction in `api-tester.ts` (`InlineRunner`, `SandboxRunner` with timeout + teardown)

## Phase 3: US1 Browser sandbox (P1)

- [ ] T006 Sandbox mode wiring in `endpoint/show.html.twig` (no `api-script-runtime.js`, frame host element)
- [ ] T007 [P] Vitest: protocol validation, ignored foreign messages, timeout
- [ ] T008 Playwright hostile corpus (DOM, cookies, storage, fetch, XHR, WebSocket, top, opener, dynamic import)

## Phase 4: US3 Richer pm (P1)

- [ ] T009 chai subset, `pm.globals`, `pm.collectionVariables`, response helpers, `require()` allow-list
- [ ] T010 `ScriptSendRequestController` through `RequestExecutor` (guard, allow-list, size, masking, budget)
- [ ] T011 `ApiVariableScope` entity + repository (binary key collation), persistence rules
- [ ] T012 [P] Vitest + functional tests (sendRequest SSRF refused, budget exceeded, globals per workspace)

## Phase 5: US5 Secrets (P1)

- [ ] T013 Placeholders only in every script-visible object; masked responses
- [ ] T014 [P] Tests asserting no secret value in script context (unit, Vitest, Playwright)

## Phase 6: US2 Server runner (P2)

- [ ] T015 Port + VOs + `ProcessScriptRunner` (limits, JSON lines, callbacks)
- [ ] T016 [P] Tests with a fake runner binary (timeout kill, memory flag, output cap); worker test 1,000 runs

## Phase 7: US4 Script levels (P2)

- [ ] T017 Workspace / folder script columns (conditional mapping), forms gated by `workspace.edit_scripts`
- [ ] T018 `ScriptChainBuilder` order and labels; Postman `event[]` import when sandbox / runner on
- [ ] T019 [P] Functional tests for the chain and the import

## Phase 8: Polish

- [ ] T020 `docs/SECURITY.md` (modes, residual risk), CONFIGURATION, USAGE, UPGRADING (CSP `frame-src 'self'`, DDL),
  CHANGELOG
- [ ] T021 Translations; code inventory rows
