# Feature Specification: Nested folders

**Feature Branch**: `002-nested-folders`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.14.0 (minor, opt-in, no BC break)
**Roadmap item**: 1 of 10 (owner-approved roadmap, 2026-11-16, relayed by Beacon)
**Input**: "Nested folders: `ApiFolder` with parent, sortOrder and a configurable max depth. Tree UI with drag & drop.
Auth and headers inherited from the folder. Postman/OpenAPI import keeps folders and tags (plus Postman scripts and
auth). A folder-level sharing hook for hosts."

## Context

Api Studio 1.13 organises requests as workspace → service → endpoint. A service plays the role of a Postman
collection, but it has no folders: the Postman importer flattens every `item[]` level and the OpenAPI importer ignores
`tags`. Large APIs (hundreds of operations) become a flat list, and auth / headers can only be set per service or per
endpoint. Postman and Apidog both offer nested folders with inherited auth.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Organise endpoints in nested folders (Priority: P1)

An editor creates folders inside a service, nests them (up to the configured depth), renames, reorders and deletes
them, and files endpoints into them. The sidebar tree shows service → folders → endpoints.

**Why this priority**: it is the base the other stories (inheritance, import, sharing) and later roadmap items (folder
scripts in 003, folder runs in 004) build on.

**Independent Test**: enable `folders.enabled`, create a service, create `Users` and `Users/Admin`, move an endpoint
into `Users/Admin`; the tree, the service page and the endpoint breadcrumb show the path.

**Acceptance Scenarios**:

1. **Given** `folders.enabled: true` and a service, **When** an editor creates folder `Users` and a child `Admin`,
   **Then** the sidebar shows `Users ▸ Admin` under the service, ordered by `sortOrder` then name.
2. **Given** `folders.max_depth: 3` and a folder at depth 3, **When** the editor creates a child folder, **Then** the
   form rejects it with a translated error and nothing is stored.
3. **Given** a folder with subfolders and endpoints, **When** it is deleted (UiKit confirm), **Then** its endpoints and
   subfolders move to its parent (or to the service root) and nothing else is deleted.
4. **Given** `folders.enabled: false` (default), **When** the studio is used, **Then** pages, queries and schema are
   exactly those of 1.13.

### User Story 2 - Drag & drop in the tree (Priority: P1)

An editor reorders endpoints and folders, and moves them between folders of the same service, by drag & drop in the
sidebar tree, with a keyboard / form alternative.

**Why this priority**: folders without cheap re-filing are not used.

**Independent Test**: drag an endpoint onto a folder, drag a folder above another; reload: order and parents persist.

**Acceptance Scenarios**:

1. **Given** the tree, **When** an editor drops endpoint E on folder F, **Then** a CSRF-protected JSON call moves E into
   F (last position) and the tree updates without a full reload.
2. **Given** a folder F, **When** it is dropped onto one of its own descendants, **Then** the move is refused (422) and
   the tree is unchanged.
3. **Given** a move that would exceed `max_depth`, **Then** it is refused (422).
4. **Given** a user without `workspace.edit`, **Then** tree items are not draggable and the move endpoint answers 403.
5. **Given** a keyboard-only user, **When** they edit the endpoint or folder, **Then** a "Folder" select moves it
   (same rules), so drag & drop is never the only way.

### User Story 3 - Inherited auth and headers (Priority: P1)

A folder may set auth (or explicitly "no auth") and headers; endpoints inside inherit them from the nearest folder,
then the service, unless the endpoint overrides.

**Why this priority**: the main reason teams use folders in Postman (one token per area).

**Independent Test**: service auth bearer `{{token}}`, folder `Admin` auth basic; an endpoint in `Admin` is sent with
Basic, an endpoint at the root with Bearer; headers merge service → folders → endpoint.

**Acceptance Scenarios**:

1. **Given** folder auth "inherit" (default), **Then** the endpoint uses the nearest ancestor with an explicit auth,
   or the service auth.
2. **Given** folder auth `none`, **Then** endpoints below are sent without auth even when the service has auth.
3. **Given** headers on service `X-A: 1`, folder `X-A: 2, X-B: 1`, endpoint `X-B: 2`, **Then** the request carries
   `X-A: 2` and `X-B: 2` (header names compared case-insensitively, the deepest wins).
4. **Given** the request console, **Then** the Headers tab shows the merged headers and the auth hint names where the
   auth comes from (service or folder path).
5. **Given** folder auth that references secret variables, **Then** the 1.11 runner rules apply unchanged (a runner
   without `workspace.edit` cannot override variables used in the auth configuration).

### User Story 4 - Import and export keep folders (Priority: P2)

Postman collections keep their folder tree and folder / request auth; OpenAPI tags become folders and folders export
as tags.

**Why this priority**: migration from Postman is the main adoption path; flattening loses the structure.

**Independent Test**: import a Postman v2.1 collection with two levels of folders and folder auth; import an OpenAPI
document with tags; export it again.

**Acceptance Scenarios**:

1. **Given** a Postman collection with nested `item[]` folders, **When** imported with folders enabled, **Then** the
   folder tree is recreated (order kept) and requests land in their folder.
2. **Given** a folder deeper than `max_depth`, **Then** its requests land in the deepest allowed ancestor and the
   import result reports how many folders were merged.
3. **Given** Postman `auth` on the collection, a folder or a request (`noauth`, `basic`, `bearer`, `apikey`), **Then**
   it maps to service / folder / endpoint auth (`inherit` when absent); unsupported types are reported, not guessed.
4. **Given** Postman `event[]` scripts, **Then** in 1.14 they are counted and reported as "not imported" (stored
   scripts from third-party files only land with the sandboxed runner of spec 003).
5. **Given** an OpenAPI operation with `tags: [Users]`, **Then** it lands in top-level folder `Users` (first tag);
   `x-api-studio-folder: [Users, Admin]` recreates a nested path; tag descriptions become folder descriptions.
6. **Given** an export, **Then** each operation carries its folder path as `x-api-studio-folder`, the top-level folder
   name as its first tag, and the document lists those tags.
7. **Given** `folders.enabled: false`, **Then** importers behave exactly as in 1.13 (flatten, no tags).

### User Story 5 - Folder-level sharing hook (Priority: P2)

A host decides per folder who may view or edit it (for example share `Partners/` with an external team), on top of the
workspace permissions.

**Why this priority**: Beacon needs per-area sharing inside a workspace; without a hook it must split workspaces.

**Independent Test**: a host checker denies `workspace.view` on folder F for user U: U does not see F (or anything
below) in the tree, its endpoints answer 404, and runs / edits inside answer 404.

**Acceptance Scenarios**:

1. **Given** no `security.folder_permission_checker` (default), **Then** folder access equals workspace access.
2. **Given** a folder checker, **Then** it is asked with the action, the user and an `ApiStudioFolderRef` (folder id,
   service id, workspace id, ancestor ids root first, creator id) and can only narrow the workspace decision, never
   widen it.
3. **Given** a denied `workspace.view` on F, **Then** F, its subfolders and their endpoints are hidden from the tree,
   lists and exports of the workspace, and their pages answer 404.
4. **Given** a denied `workspace.edit` on F (view granted), **Then** F and its content are read-only (403 on edit,
   delete, move, example saves), and drops into F are refused.
5. **Given** a denial on an ancestor, **Then** it applies to every descendant (the checker is asked for the nearest
   folder; the bundle walks the ancestors).

### Edge Cases

- Moving an endpoint to a folder of another service: refused (422); folders never span services.
- Concurrent moves creating a cycle: the move re-reads the target ancestors inside the transaction and refuses cycles.
- Deleting a service deletes its folders (cascade), as it deletes its endpoints today.
- Duplicate folder names under the same parent are allowed (Postman allows them); slugs are not needed (folders are
  addressed by id).
- `max_depth` lowered after folders exist: existing deeper folders stay usable; only new folders / moves are checked.
- Import into an existing service: folders are matched by name path under the same parent and reused.
- Tenancy: folders inherit the workspace tenant through their service; every lookup goes through the scoped service.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-FOLD-001**: `nowo_api_studio.folders.enabled` (bool, default `false`) turns folders on. Off = 1.13 schema,
  queries, pages and importers.
- **FR-FOLD-002**: `nowo_api_studio.folders.max_depth` (int 1–32, default 8). A top-level folder has depth 1.
- **FR-FOLD-003**: `ApiFolder` belongs to one service, has an optional parent folder of the same service, a name
  (≤ 255), an optional description, `sortOrder`, optional auth (`authType` null = inherit, plus `authConfig`), headers
  (JSON map), an optional creator id (≤ 128, exact binary comparison) and timestamps.
- **FR-FOLD-004**: `ApiEndpoint` gains an optional folder (same service); null = service root.
- **FR-FOLD-005**: Folder CRUD pages (UiKit form scaffold, FormKit form), delete through the UiKit confirm contract;
  deleting moves children and endpoints to the parent.
- **FR-FOLD-006**: Sidebar tree and service page render folders nested, ordered by `sortOrder`, then name; endpoints
  after folders within a level.
- **FR-FOLD-007**: Drag & drop move / reorder through `POST .../services/{serviceId}/tree/move` (JSON, CSRF header,
  `workspace.edit`), validating same service, no cycle, depth; a "Folder" select on endpoint and folder forms is the
  accessible alternative.
- **FR-FOLD-008**: Effective auth = endpoint override (none in 1.14) → nearest folder with an explicit auth → service.
  Effective headers = service defaults, then each folder from root to leaf, then endpoint headers (case-insensitive
  names, deepest wins). Used by the executor, the console and the documentation.
- **FR-FOLD-009**: Postman import recreates folders (order, nesting up to `max_depth`) and maps collection / folder /
  request auth; scripts are reported, not imported (spec 003).
- **FR-FOLD-010**: OpenAPI import maps `x-api-studio-folder` (path) or the first tag (top-level folder) and tag
  descriptions; export writes `x-api-studio-folder` and the top-level folder as the first tag.
- **FR-FOLD-011**: Optional SPI `ApiStudioFolderPermissionCheckerInterface`
  (`security.folder_permission_checker`): `isFolderGranted(?object $user, ApiStudioAction $action,
  ApiStudioFolderRef $folder): bool`; it can only deny. Hidden folders and their content are filtered from the tree,
  lists and exports and answer 404.
- **FR-FOLD-012**: `ApiStudioResourceChangedEvent::TYPE_FOLDER` (+ `ACTION_MOVED`) is dispatched after commit for folder
  create / update / delete / move and for endpoint moves, carrying ids only.
- **FR-FOLD-013**: Every new string or page is translated in the 7 catalogues; UI uses UiKit classes / dialogs and
  FormKit forms (no raw form markup, no inline handlers, strict CSP).

### Key Entities

- **ApiFolder**: a named node of a service's tree (parent, children, endpoints), with inheritable auth and headers.
- **ApiStudioFolderRef** (VO): folderId, serviceId, workspaceId, ancestorIds (root first), createdBy.

## Success Criteria *(mandatory)*

- **SC-001**: With folders off, the PHPUnit suite and the schema diff against 1.13 are unchanged (no new table, no new
  column).
- **SC-002**: A 2-level Postman collection with folder auth round-trips into the same tree and the requests are sent
  with the inherited auth (functional test with a mocked HTTP client).
- **SC-003**: Tree of 500 endpoints in 50 folders renders with one navigation query (no N+1).
- **SC-004**: A worker test runs 1,000 tree builds / moves for two tenants in one kernel without state leaking between
  them and with flat memory.
- **SC-005**: Playwright: create folders, drag an endpoint into a folder, reload, the endpoint is inside; keyboard move
  through the form.
- **SC-006**: PHP coverage stays at the bundle gate (≥ 99 % lines).

## Assumptions

- Folders live inside a service (Postman collection = service). Workspace-level folders are out of scope.
- Endpoint-level auth override is not added in 1.14 (endpoints inherit only); it can come later as an additive field.
- Folder scripts (pre / post) arrive with spec 003, on the same table.
- Folders are addressed by id; no slug, no public URL.
