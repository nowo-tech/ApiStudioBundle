---
description: "Tasks for 010 docs portal (1.22.0)"
---

# Tasks: Published docs portal

- [ ] T001 `docs_portal.*` configuration + path prefix parameter
- [ ] T002 `ApiDocsPortal` entity (binary NO PAD slug), conditional mapping
- [ ] T003 Access port `ApiStudioDocsPortalAccessInterface` + default adapter; `DocsPortalRef` VO
- [ ] T004 `DocsPortalViewBuilder` (folder filter, secret-free data, OpenAPI download)
- [ ] T005 Public controller + UiKit public pages; admin page (publish, slug, visibility, intro)
- [ ] T006 Markdown safe rendering (commonmark optional), ETag / caching rules
- [ ] T007 [P] Tests: secrets / env values / hidden folders never rendered; access denied → 404; page contract
- [ ] T008 Worker test; docs (security.yaml for public portals), UPGRADING DDL, CHANGELOG, translations, inventory
- [ ] T009 Playwright: publish a portal and read it anonymously in the demo
