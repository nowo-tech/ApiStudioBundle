---
description: "Tasks for 002 nested folders (1.14.0)"
---

# Tasks: Nested folders

**Input**: [spec.md](spec.md), [plan.md](plan.md)
**Tests**: required (constitution II): PHPUnit, Vitest, Playwright.

## Phase 1: Setup

- [ ] T001 Add `folders.enabled`, `folders.max_depth` and `security.folder_permission_checker` to
  `src/DependencyInjection/Configuration.php`; container parameters in `ApiStudioExtension.php`
- [ ] T002 [P] Document the options in `docs/CONFIGURATION.md`

## Phase 2: Foundational

- [ ] T003 `src/Entity/ApiFolder.php` + `src/Repository/ApiFolderRepository.php` (tree rows per service, one query)
- [ ] T004 `ApiEndpoint::$folder` (unmapped property) + accessors; `TablePrefixSubscriber` maps it and `ApiFolder` only
  when enabled, and sets the NO PAD collation of `created_by` on MySQL / MariaDB
- [ ] T005 `src/Service/FolderTree.php`: depth, ancestors, cycle / service / depth checks, move + reorder in a transaction
- [ ] T006 [P] Unit tests: mapping on / off (schema identical to 1.13 when off), collation per platform, FolderTree rules

## Phase 3: US1 Folder CRUD (P1)

- [ ] T007 `src/Form/ApiFolderFormType.php` (FormKit), `src/Controller/ApiFolderController.php` new / edit / delete
- [ ] T008 `src/Resources/views/folder/form.html.twig` (UiKit form scaffold), page factory entries, route actions
- [ ] T009 Delete moves children and endpoints to the parent; UiKit confirm dialog
- [ ] T010 [P] Functional tests: CRUD, max depth, delete keeps endpoints, folders off = 404 on folder routes
- [ ] T011 [P] Page contract test for the folder form page

## Phase 4: US2 Tree and drag & drop (P1)

- [ ] T012 `StudioNavigationProvider` + `navigationRows()` return folders (single query) and the tree nests them
- [ ] T013 `_sidebar_tree.html.twig` recursive folders with `data-tree-*` attributes (draggable only with
  `workspace.edit`)
- [ ] T014 `POST /workspaces/{w}/services/{s}/tree/move` JSON endpoint (CSRF header, 403 / 422 rules)
- [ ] T015 `api-studio-shell.ts` drag & drop controller; rebuild `Resources/public/api-studio-shell.js`
- [ ] T016 Folder select on the endpoint form and parent select on the folder form (accessible move)
- [ ] T017 [P] Vitest `tree-dnd.test.ts`; functional tests for the move endpoint (cycle, depth, other service, 403)

## Phase 5: US3 Inheritance (P1)

- [ ] T018 `src/Service/FolderInheritanceResolver.php` (auth source + merged headers)
- [ ] T019 `RequestExecutor` and `EndpointTesterFormFactory` / endpoint page use it; auth hint names the source
- [ ] T020 [P] Unit tests (inherit, explicit none, header precedence, folders off = 1.13 output) and an execute
  functional test with a mocked HTTP client

## Phase 6: US4 Import / export (P2)

- [ ] T021 Postman importer: folders (order, depth cap), auth mapping, scripts counted as not imported in `ImportResult`
- [ ] T022 OpenAPI importer: `x-api-studio-folder` / first tag, tag descriptions; exporter writes them
- [ ] T023 [P] Fixtures + functional tests (folders on and off)

## Phase 7: US5 Sharing hook (P2)

- [ ] T024 `ApiStudioFolderPermissionCheckerInterface`, `ApiStudioFolderRef`, `FolderAccess` (per-request memo,
  `ResetInterface`)
- [ ] T025 Apply it to the tree, service page, endpoint routes, execute, examples, exports and moves
- [ ] T026 [P] Functional tests: hidden folder → 404 + filtered tree; read-only folder → 403; ancestor denial inherited

## Phase 8: Polish

- [ ] T027 Events `TYPE_FOLDER` / `ACTION_MOVED` after commit
- [ ] T028 Translations (7 catalogues), `docs/USAGE.md`, `docs/UPGRADING.md` (migration DDL), CHANGELOG `[Unreleased]`
- [ ] T029 Worker test: 1,000 tree builds / moves, two tenants, flat memory
- [ ] T030 Playwright `folders.spec.ts` (create, drag & drop, reload, keyboard move); demo seeds a folder tree
- [ ] T031 `specs/001-baseline/code-inventory.md` rows for every new `src/` file
