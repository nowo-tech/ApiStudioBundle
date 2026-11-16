---
description: "Tasks for 004 test cases, collection runner and CLI (1.16.0)"
---

# Tasks: Test cases, collection runner and CLI

## Phase 1: Setup

- [ ] T001 `runner.*` configuration + parameters; `opis/json-schema` in `suggest`
- [ ] T002 Entities `ApiAssertion`, `ApiRun`, `ApiRunResult` + conditional mapping (NO PAD `started_by`)

## Phase 2: US1 Assertions (P1)

- [ ] T003 Evaluators: status, header, JSONPath, XPath (XXE-safe), JSON Schema, response time
- [ ] T004 Assertion editor on the endpoint page (FormKit collection), validation of expressions
- [ ] T005 Console shows assertion results after Send (execute JSON gains `assertions`)
- [ ] T006 [P] Unit tests per evaluator incl. XXE and masked excerpts

## Phase 3: US2 Runner (P1)

- [ ] T007 `CollectionRunner`, `RunPlan`, `RunReport`; tree order from `FolderTree`
- [ ] T008 Reporters JSON / JUnit / HTML / CLI
- [ ] T009 `RunController` pages (start, progress, cancel, report, downloads); optional Messenger handler
- [ ] T010 [P] Functional tests with mocked HTTP client (iterations, data file, bail, scripts not run)

## Phase 4: US3 CLI (P1)

- [ ] T011 `RunCommand` (+ alias), exit codes, tenant options, allow-list guard
- [ ] T012 [P] Command tests (exit codes, JUnit output)

## Phase 5: Polish

- [ ] T013 Purge command + retention; events after commit
- [ ] T014 Worker test (500 runs, two tenants); docs (USAGE CI example), UPGRADING DDL, CHANGELOG, translations,
  inventory
- [ ] T015 Playwright: run a folder from the UI and open the report
