---
description: "Tasks for 007 interop (1.19.0)"
---

# Tasks: Interop

- [ ] T001 `import.*` configuration
- [ ] T002 [P] `CurlCommandParser` + `CurlImporter` + fuzz test; credentials → secret variables
- [ ] T003 [P] `HarImporter` (dedupe, response examples, credential stripping)
- [ ] T004 [P] `InsomniaImporter` (groups → folders, environments)
- [ ] T005 [P] `WsdlImporter` (guarded loader, XXE-safe, envelope templates, SOAP 1.1 / 1.2)
- [ ] T006 Import UI: format list, cURL paste field (FormKit), result summary
- [ ] T007 `PostmanCollectionExporter` + schema validation test + round trip test
- [ ] T008 `api-code-snippets.ts` (fetch, PHP HttpClient / cURL, Python requests, Go) + Vitest snapshots + secret test
- [ ] T009 Worker test (200 imports per format); docs (USAGE import / export matrix), CHANGELOG, translations, inventory
- [ ] T010 Playwright: import a cURL command, open the Code panel
