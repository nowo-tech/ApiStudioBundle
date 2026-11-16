# Implementation Plan: Nested folders

**Branch**: `002-nested-folders` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.14.0** (minor). Opt-in (`folders.enabled: false` by default): no BC break.

## Summary

Add `ApiFolder` (service-scoped tree, parent + sortOrder + max depth), an optional `folder` on `ApiEndpoint`, folder
CRUD and a drag & drop tree, inherited auth / headers resolved by a stateless resolver used by the executor and the
console, folder-aware Postman / OpenAPI import and OpenAPI export, and an optional folder permission SPI. Everything is
mapped and wired only when `folders.enabled: true`.

## Technical Context

**Language/Version**: PHP 8.2–8.5, TypeScript (Vite IIFE builds committed under `Resources/public`)
**Primary Dependencies**: Symfony 7.4 / 8, Doctrine ORM 3.7, UiKitBundle ^2.17.2, FormKitBundle ^2.14
**Storage**: Doctrine ORM on the Api Studio entity manager; MySQL / MariaDB / PostgreSQL / SQLite
**Testing**: PHPUnit (unit, functional kernels, page contract), Vitest (jsdom), Playwright (demo)
**Target Platform**: Symfony bundle, FrankenPHP worker mode with kernel reset false
**Constraints**: no N+1 in the tree; strict CSP (no inline handlers); worker-safe services
**Scale/Scope**: services with hundreds of endpoints, depth ≤ 32

## Constitution Check

- I. Documented contract: spec + CONFIGURATION / USAGE / UPGRADING updated. ✅
- II. Spec-first, test-proven: unit + functional + Vitest + Playwright listed in tasks. ✅
- III. Inventory: every new `src/` file added to `specs/001-baseline/code-inventory.md`. ✅
- V. Symfony compatibility: no new runtime dependency. ✅

## Configuration

```yaml
nowo_api_studio:
    folders:
        enabled: false        # 1.14: opt-in (maps api_studio_folder + api_studio_endpoint.folder_id; migration)
        max_depth: 8          # 1..32, a top-level folder has depth 1
    security:
        folder_permission_checker: null   # service id implementing ApiStudioFolderPermissionCheckerInterface
```

## Data model

`{prefix}folder` (mapped only when `folders.enabled`; otherwise the class is treated as a mapped superclass so
`schema:update` / `migrations:diff` see no table):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int PK | |
| `service_id` | int FK → `{prefix}service.id` | `ON DELETE CASCADE`, not null |
| `parent_id` | int FK → `{prefix}folder.id` | nullable, `ON DELETE CASCADE` (the app moves children up first) |
| `name` | varchar(128) | same limit as endpoint names |
| `description` | text | nullable |
| `sort_order` | int | default 0 |
| `auth_type` | varchar(32) | nullable = inherit; `AuthType` values |
| `auth_config` | json | default `[]` |
| `headers` | json | default `[]` |
| `created_by` | varchar(128) | nullable; **binary NO PAD collation** on MySQL (`utf8mb4_0900_bin`) / MariaDB (`utf8mb4_nopad_bin`), set by the metadata listener; exact compare in PHP too |
| `created_at`, `updated_at` | datetime_immutable | |

Indexes: `idx_folder_service_parent (service_id, parent_id, sort_order)`.

`{prefix}endpoint.folder_id` int nullable FK → `{prefix}folder.id` `ON DELETE SET NULL`, index
`idx_endpoint_folder (folder_id)`. Mapped by the metadata listener only when folders are enabled (same pattern as
`tenant_*` and `executed_by`).

### Migrations

The bundle ships no migration classes (hosts own their migrations, possibly on a dedicated entity manager). UPGRADING
documents `doctrine:migrations:diff --em=<api studio em>` / `nowo:api-studio:sync-schema` and gives reference DDL for
MySQL 8, MariaDB, PostgreSQL and SQLite. The `created_by` collation is emitted by the mapping so `diff` keeps it.

## Architecture

- `Entity/ApiFolder` + `Repository/ApiFolderRepository` (tree rows for a service in one query; ancestors by walking
  loaded rows, never lazy recursion).
- `Doctrine/TablePrefixSubscriber`: maps `ApiEndpoint::$folder` when enabled; marks `ApiFolder` a mapped superclass when
  disabled; sets the NO PAD collation of `created_by` on MySQL-family platforms.
- `Service/FolderTree` (stateless): depth, ancestors, cycle checks and moves (inside an explicit transaction on the Api
  Studio EM, `clear()` after the write unit).
- `Service/FolderInheritanceResolver` (pure static functions over the entity graph): effective auth source and merged
  headers for an endpoint; used by `RequestExecutor` (including the ER-M1 protected / referenced variables),
  `ApiExecuteController` (history redaction) and the endpoint page.
- `Controller/ApiFolderController`: new / edit / delete pages, `tree/move` JSON endpoint. Route actions mapped in
  `ApiStudioRouteActions` (`workspace.view` / `workspace.edit`).
- `Form/ApiFolderFormType` (FormKit profile `api_studio`), `ApiEndpointFormType` gains an optional `folder` choice
  (only when enabled).
- `Security/ApiStudioFolderPermissionCheckerInterface`, `Security/ApiStudioFolderRef`, `Security/FolderAccess`
  (applies the SPI after the workspace decision; decisions memoised on the main request's attributes, like
  `ApiStudioAuthorization`, so nothing is kept in the service).
- Importers: `PostmanCollectionImporter` and `OpenApiImporter` gain a folder path per created endpoint;
  `OpenApiExporter` writes `x-api-studio-folder` + tags.
- UI: `_sidebar_tree.html.twig` renders folders recursively (macro), items carry `data-tree-*` attributes;
  `api-studio-shell.ts` gains the drag & drop controller (HTML5 DnD + pointer fallback is not needed: the form select
  is the accessible path). Folder pages use UiKit scaffolds; delete uses the UiKit confirm `<dialog>`.

## BC analysis

| Change | BC impact | Mitigation |
| --- | --- | --- |
| New table + endpoint column | Schema change | Opt-in: unmapped while `folders.enabled: false`; UPGRADING gives the migration |
| `ApiStudioSubject` unchanged; new `ApiStudioFolderRef` VO + optional SPI | Additive | Default null = 1.13 behaviour |
| `ApiStudioResourceChangedEvent::TYPE_FOLDER`, `ACTION_MOVED` | Additive constants | Listeners that switch on type should ignore unknown types (documented since 1.12) |
| `RequestExecutor` header / auth assembly goes through the resolver | Same result without folders | Unit tests pin 1.13 output with folders off |
| Postman / OpenAPI importers | Same output with folders off | Functional tests with folders off |
| Templates `_sidebar_tree`, `service/show`, `endpoint/form` gain folder markup | Overrides keep working; new blocks only render with folders on | UPGRADING lists the new blocks |
| Endpoint-level auth override, workspace-level folders | Not in 1.14 | Later minor, additive |

Nothing requires a 2.0. In 2.0 `folders.enabled` may default to `true`.

## FrankenPHP worker mode (kernel reset false)

- `FolderTree`, `FolderInheritanceResolver`, importers and exporter keep no request, user or tenant data in properties
  or statics; everything is passed per call.
- `FolderAccess` memoises decisions on the main request's attributes only (request-scoped; a new request asks again,
  tested), so no memo survives in the service.
- Moves run in an explicit transaction on the Api Studio EM; the EM is cleared after each write unit; a closed EM is
  recovered by the existing `EntityManagerRecoverySubscriber`.
- Worker test: 1,000 tree builds and moves alternating two tenants / users in one kernel, asserting no cross-tenant
  rows and flat memory.

## Project Structure

```text
src/Entity/ApiFolder.php
src/Repository/ApiFolderRepository.php
src/Service/FolderTree.php
src/Service/FolderInheritanceResolver.php
src/Controller/ApiFolderController.php
src/Form/ApiFolderFormType.php
src/Security/ApiStudioFolderPermissionCheckerInterface.php
src/Security/ApiStudioFolderRef.php
src/Security/FolderAccess.php
src/Resources/views/folder/form.html.twig
src/Resources/views/_sidebar_tree.html.twig            (folders, recursive)
src/Resources/assets/src/api-studio-shell.ts           (drag & drop)
tests/Unit/... tests/Functional/Folders/... src/Resources/assets/tests/tree-dnd.test.ts
demo/symfony8/e2e/tests/folders.spec.ts
```

## Complexity Tracking

None.
