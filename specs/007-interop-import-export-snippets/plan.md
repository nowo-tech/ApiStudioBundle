# Implementation Plan: Interop — imports, Postman export, code snippets

**Branch**: `007-interop-import-export-snippets` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)
**Target version**: **1.19.0** (minor, additive)

## Summary

Four new importers (cURL, HAR, Insomnia v4, WSDL), a Postman v2.1 exporter and a client-side code snippet panel.

## Configuration

```yaml
nowo_api_studio:
    import:
        max_entries: 5000
        wsdl_max_schema_depth: 8
```

## Data model and migrations

None. Importers write existing entities (services, folders when enabled, endpoints, examples, environments,
variables). No migration.

## Architecture

- `Service/ImportExport/CurlCommandParser` (POSIX-like quoting only; rejects substitutions), `CurlImporter`.
- `HarImporter`, `InsomniaImporter` (request groups → folders when spec 002 folders are enabled, otherwise flattened
  like the 1.13 Postman importer).
- `WsdlImporter`: `GuardedWsdlLoader` for the document and imports; XSD walker (depth-limited) produces envelope
  templates; `LIBXML_NONET`, no external entities.
- `PostmanCollectionExporter`: `ImportExportController` route `…/export/postman`.
- `api-code-snippets.ts` (new Vite entry or part of `api-tester`), "Code" sub-tab in `endpoint/show.html.twig`.
- `DocumentParser` detection order: Postman, OpenAPI, Insomnia, HAR, WSDL; cURL is a paste field.

## BC analysis

Additive. `DocumentParser::detect()` returns new format names; callers that switch on the format get new values
(internal class). `ImportResult` gains counters (additive constructor arguments with defaults). No BC break.

## FrankenPHP worker mode (kernel reset false)

Parsers, importers and exporters are stateless; large documents are processed per call with explicit limits; the EM
is cleared after each import unit (batched flush). Worker test: 200 imports of each format in one kernel, flat memory.
