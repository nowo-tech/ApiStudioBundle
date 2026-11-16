# Feature Specification: Published docs portal per workspace

**Feature Branch**: `010-docs-portal`
**Created**: 2026-11-16
**Status**: Draft
**Target version**: 1.22.0 (minor, opt-in)
**Roadmap item**: 9 of 10
**Input**: "Optional published docs portal per workspace, with host branding and host permissions."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Publish a workspace's documentation (Priority: P1)

A workspace manager publishes a read-only documentation site of the workspace: services, folders (spec 002),
endpoints with their translated title / description, parameters, headers (without secret values), request / response
examples and an OpenAPI download. URL: `<prefix>/api-docs/v1/{portalSlug}`.

**Acceptance Scenarios**:

1. **Given** `docs_portal.enabled: true` and a published workspace, **Then** the portal renders with UiKit public
   layout and the host brand.
2. **Given** an unpublished workspace, **Then** 404.
3. **Given** secret variables, **Then** they appear as `{{name}}` placeholders only; environments' values are never
   shown (only names marked "documented").

### User Story 2 - Host permissions (Priority: P1)

**Acceptance Scenarios**:

1. **Given** visibility `public`, **Then** anonymous readers can see it (the host must allow the prefix in
   `security.yaml`; documented).
2. **Given** visibility `host`, **Then** the bundle asks the host port `ApiStudioDocsPortalAccessInterface::canRead(?object
   $user, DocsPortalRef $portal): bool` (default: deny everyone but users passing `security.access_roles`).
3. **Given** folder sharing (spec 002), **Then** folders hidden by the folder checker for the reader are hidden in the
   portal too.

### User Story 3 - Host branding (Priority: P2)

The portal uses the host's UiKit theme and, when present, the BrandContracts port (logo, colours, name) per project /
tenant; a portal may override title, intro text (Markdown rendered safely) and a logo from the host brand only (no
uploads in this spec).

## Requirements *(mandatory)*

- **FR-DOC-001**: Config `docs_portal.enabled` (false), `docs_portal.path_prefix` (`/api-docs`), `docs_portal.access`
  (service id, optional).
- **FR-DOC-002**: Entity `ApiDocsPortal` (workspace one-to-one, slug unique **binary collation**, visibility
  public / host, title, intro markdown, published bool, updated_at).
- **FR-DOC-003**: Public, versioned routes `<prefix>/api-docs/v1/{slug}`, `/v1/{slug}/services/{id}`,
  `/v1/{slug}/openapi.json`; OpenAPI of the portal surface.
- **FR-DOC-004**: Managing a portal requires `workspace.manage`.
- **FR-DOC-005**: Markdown rendered with `league/commonmark` (suggest) in safe mode (no raw HTML, no unsafe links), or
  shown as plain text without it.
- **FR-DOC-006**: HTTP caching: `ETag` from the workspace `updated_at` max; `Cache-Control: public` only for public
  portals.

## Success Criteria *(mandatory)*

- **SC-001**: No secret value, environment value or hidden folder appears in any portal page or download (tests).
- **SC-002**: Page contract test for portal pages (UiKit public scaffold).
- **SC-003**: Worker test: 2,000 portal renders across two tenants, flat memory.
