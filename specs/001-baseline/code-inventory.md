# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/api-studio-bundle`  
**Last audited**: 2026-07-07

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and demo trees are out of Packagist scope unless promoted in the spec.

## Bundle & DI (`src/` root + `DependencyInjection/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `ApiStudioBundle.php` | Bundle entry, alias `nowo_api_studio` | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree (`enabled`, `connection`, `table_prefix`, `environments`, `ui.path`, locales) | FR-CFG-001 |
| `DependencyInjection/ApiStudioExtension.php` | DI extension, parameter wiring | FR-CFG-001, FR-DI-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace `@NowoApiStudioBundle` | FR-CFG-001, FR-TWIG-001 |

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service autowiring and tags | FR-DI-001 |
| `Resources/config/routes.yaml` | Studio HTTP routes | FR-DI-001, FR-CTRL-001 |

## Doctrine (`src/Doctrine/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/TablePrefixSubscriber.php` | Configurable table prefix on ORM metadata | FR-ORM-002 |

## Entities (`src/Entity/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/ApiWorkspace.php` | Workspace root aggregate | FR-ORM-001 |
| `Entity/ApiService.php` | Service collection under workspace | FR-ORM-001 |
| `Entity/ApiEndpoint.php` | Endpoint definition (REST/SOAP/GraphQL) | FR-ORM-001 |
| `Entity/ApiEndpointTranslation.php` | Localized endpoint metadata | FR-ORM-001, FR-I18N-001 |
| `Entity/ApiEnvironment.php` | Named environment (dev/test/prod) | FR-ORM-001 |
| `Entity/ApiEnvironmentVariable.php` | Key/value variables per environment | FR-ORM-001 |
| `Entity/ApiRequestExample.php` | Saved request payloads | FR-ORM-001 |
| `Entity/ApiResponseExample.php` | Saved response samples | FR-ORM-001 |
| `Entity/ApiRequestHistory.php` | Execution audit trail | FR-ORM-001, FR-EXEC-001 |

## Enums (`src/Enum/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Enum/HttpMethod.php` | HTTP verb enumeration | FR-ORM-001, FR-EXEC-001 |
| `Enum/AuthType.php` | Auth scheme enumeration | FR-ORM-001, FR-EXEC-001 |
| `Enum/ApiProtocol.php` | REST / SOAP / GraphQL protocol | FR-ORM-001, FR-EXEC-001 |

## Repositories (`src/Repository/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/ApiWorkspaceRepository.php` | Workspace persistence | FR-ORM-002 |
| `Repository/ApiServiceRepository.php` | Service persistence | FR-ORM-002 |
| `Repository/ApiEndpointRepository.php` | Endpoint persistence | FR-ORM-002 |
| `Repository/ApiEndpointTranslationRepository.php` | Translation persistence | FR-ORM-002 |
| `Repository/ApiEnvironmentRepository.php` | Environment persistence | FR-ORM-002 |
| `Repository/ApiEnvironmentVariableRepository.php` | Variable persistence | FR-ORM-002 |
| `Repository/ApiRequestExampleRepository.php` | Request example persistence | FR-ORM-002 |
| `Repository/ApiResponseExampleRepository.php` | Response example persistence | FR-ORM-002 |
| `Repository/ApiRequestHistoryRepository.php` | History persistence | FR-ORM-002 |

## Controllers (`src/Controller/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/DashboardController.php` | Studio home dashboard | FR-CTRL-001 |
| `Controller/ApiWorkspaceController.php` | Workspace CRUD | FR-CTRL-001 |
| `Controller/ApiServiceController.php` | Service CRUD | FR-CTRL-001 |
| `Controller/ApiEndpointController.php` | Endpoint CRUD | FR-CTRL-001 |
| `Controller/ApiEnvironmentController.php` | Environment CRUD | FR-CTRL-001 |
| `Controller/EnvironmentVariablesController.php` | Environment variable editor | FR-CTRL-001 |
| `Controller/EndpointExamplesController.php` | Request/response example CRUD | FR-CTRL-001 |
| `Controller/ImportExportController.php` | Import/export hub and actions | FR-CTRL-001, FR-IMP-001 |
| `Controller/ApiExecuteController.php` | In-browser request execution API | FR-CTRL-001, FR-EXEC-001 |
| `Controller/LocaleController.php` | Locale switcher route | FR-CTRL-001, FR-I18N-001 |

## Forms (`src/Form/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ApiWorkspaceFormType.php` | Workspace create/edit form | FR-FORM-001 |
| `Form/ApiServiceFormType.php` | Service create/edit form | FR-FORM-001 |
| `Form/ApiEndpointFormType.php` | Endpoint create/edit form | FR-FORM-001 |
| `Form/ApiEnvironmentFormType.php` | Environment create/edit form | FR-FORM-001 |
| `Form/JsonMapType.php` | Key/value JSON map field | FR-FORM-001 |
| `Form/ImportFileFormType.php` | OpenAPI/Postman upload form | FR-FORM-001, FR-IMP-001 |

## Models (`src/Model/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Model/ApiExecutionResult.php` | Execution response DTO | FR-EXEC-001 |
| `Model/ImportResult.php` | Import summary DTO | FR-IMP-002 |

## Services — core (`src/Service/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/RequestExecutor.php` | HTTP/SOAP request runner | FR-EXEC-001 |
| `Service/VariableResolver.php` | `{{var}}` placeholder expansion | FR-EXEC-002 |
| `Service/VariableSyntax.php` | Variable token parsing/formatting | FR-EXEC-002 |
| `Service/PayloadBodyHelper.php` | JSON/form body normalization | FR-EXEC-004 |
| `Service/EnvironmentContextBuilder.php` | Active environment base URL and vars | FR-EXEC-004 |
| `Service/SchemaSyncService.php` | Doctrine schema alignment | FR-CLI-001 |
| `Service/DemoSeedService.php` | Demo workspace seed data | FR-CLI-001 |
| `Service/LocaleManager.php` | Enabled locales and defaults | FR-I18N-001 |
| `Service/StudioNavigationProvider.php` | Sidebar workspace→service→endpoint tree | FR-TWIG-001 |

## Services — ImportExport (`src/Service/ImportExport/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/ImportExport/DocumentParser.php` | Detect OpenAPI vs Postman format | FR-IMP-002 |
| `Service/ImportExport/SlugHelper.php` | Unique slug generation on import | FR-IMP-002 |
| `Service/ImportExport/OpenApiImporter.php` | OpenAPI → entities merge | FR-IMP-001 |
| `Service/ImportExport/PostmanCollectionImporter.php` | Postman collection → entities merge | FR-IMP-001 |
| `Service/ImportExport/OpenApiExporter.php` | Entities → OpenAPI document | FR-IMP-001 |
| `Service/ImportExport/EnvironmentVariableExporter.php` | Variables → export file | FR-IMP-001 |
| `Service/ImportExport/EnvironmentVariableImporter.php` | Import file → variables | FR-IMP-001 |

## Security (`src/Security/` + access subscriber)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ApiStudioAccessCheckerInterface.php` | Host-app access policy contract | FR-SEC-001 |
| `Security/ConfigurableApiStudioAccessChecker.php` | Default role/config checker | FR-SEC-001 |
| `Security/ExecutionUrlValidator.php` | SSRF / open-redirect guard | FR-EXEC-003 |
| `EventSubscriber/ApiStudioAccessSubscriber.php` | Route-level access enforcement | FR-SEC-001 |

## CLI commands (`src/Command/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/SyncSchemaCommand.php` | `api-studio:sync-schema` CLI | FR-CLI-001 |
| `Command/SeedDemoCommand.php` | `api-studio:seed-demo` CLI | FR-CLI-001 |

## Event listeners (`src/EventListener/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `EventListener/LocaleSubscriber.php` | Request locale from session/config | FR-I18N-001 |

## Twig extension (`src/Twig/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/ApiStudioExtension.php` | Nav globals, method CSS, variable helpers | FR-TWIG-001 |

## Translations (`src/Resources/translations/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoApiStudioBundle.en.yaml` | English UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.es.yaml` | Spanish UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.de.yaml` | German UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.fr.yaml` | French UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.it.yaml` | Italian UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.nl.yaml` | Dutch UI strings | FR-I18N-001 |
| `Resources/translations/NowoApiStudioBundle.pt.yaml` | Portuguese UI strings | FR-I18N-001 |

## TypeScript source (`src/Resources/assets/src/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/api-tester.ts` | In-browser request tester panel | FR-UI-001, FR-EXEC-001 |
| `Resources/assets/src/api-studio-shell.ts` | Studio shell chrome and navigation | FR-UI-001 |
| `Resources/assets/src/api-body-tools.ts` | Request body editor helpers | FR-UI-001, FR-EXEC-004 |
| `Resources/assets/src/api-script-runtime.ts` | Pre/post-request script runner | FR-UI-001 |
| `Resources/assets/src/api-endpoint-doc.ts` | Endpoint documentation panel | FR-UI-001 |
| `Resources/assets/src/api-form-locale-tabs.ts` | Translatable form locale tabs | FR-UI-001, FR-I18N-001 |
| `Resources/assets/src/lib/utils.ts` | Shared DOM/HTTP utilities | FR-UI-001 |
| `Resources/assets/src/types/global.d.ts` | Window/global type declarations | FR-UI-001 |

## Public assets — built (`src/Resources/public/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/public/api-tester.js` | Built tester bundle | FR-UI-001 |
| `Resources/public/api-studio-shell.js` | Built shell bundle | FR-UI-001 |
| `Resources/public/api-body-tools.js` | Built body-tools bundle | FR-UI-001 |
| `Resources/public/api-script-runtime.js` | Built script-runtime bundle | FR-UI-001 |
| `Resources/public/api-endpoint-doc.js` | Built endpoint-doc bundle | FR-UI-001 |
| `Resources/public/api-form-locale-tabs.js` | Built locale-tabs bundle | FR-UI-001 |
| `Resources/public/api-studio-theme.css` | Studio theme stylesheet | FR-UI-002 |

## Twig views (`src/Resources/views/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/layout.html.twig` | Base studio layout | FR-TWIG-001 |
| `Resources/views/_sidebar_tree.html.twig` | Workspace/service sidebar tree | FR-TWIG-001 |
| `Resources/views/_sidebar_endpoints.html.twig` | Endpoint list in sidebar | FR-TWIG-001 |
| `Resources/views/_locale_switcher.html.twig` | Locale dropdown partial | FR-TWIG-001, FR-I18N-001 |
| `Resources/views/_variable_catalog.html.twig` | Environment variable reference | FR-TWIG-001, FR-EXEC-002 |
| `Resources/views/_endpoint_expandable_list.html.twig` | Collapsible endpoint list | FR-TWIG-001 |
| `Resources/views/_service_collection_list.html.twig` | Service listing partial | FR-TWIG-001 |
| `Resources/views/_service_kebab_menu.html.twig` | Service context menu | FR-TWIG-001 |
| `Resources/views/_service_action_modals.html.twig` | Service action modals | FR-TWIG-001 |
| `Resources/views/dashboard/index.html.twig` | Dashboard overview | FR-TWIG-001 |
| `Resources/views/workspace/index.html.twig` | Workspace index | FR-TWIG-001 |
| `Resources/views/workspace/form.html.twig` | Workspace form | FR-TWIG-001, FR-FORM-001 |
| `Resources/views/workspace/show.html.twig` | Workspace detail | FR-TWIG-001 |
| `Resources/views/service/index.html.twig` | Service index | FR-TWIG-001 |
| `Resources/views/service/form.html.twig` | Service form | FR-TWIG-001, FR-FORM-001 |
| `Resources/views/service/show.html.twig` | Service detail | FR-TWIG-001 |
| `Resources/views/endpoint/index.html.twig` | Endpoint index | FR-TWIG-001 |
| `Resources/views/endpoint/form.html.twig` | Endpoint form with locale tabs | FR-TWIG-001, FR-FORM-001, FR-I18N-001 |
| `Resources/views/endpoint/show.html.twig` | Endpoint detail and tester embed | FR-TWIG-001, FR-UI-001 |
| `Resources/views/environment/index.html.twig` | Environment index | FR-TWIG-001 |
| `Resources/views/environment/form.html.twig` | Environment form | FR-TWIG-001, FR-FORM-001 |
| `Resources/views/environment/show.html.twig` | Environment detail | FR-TWIG-001 |
| `Resources/views/import_export/hub.html.twig` | Import/export hub | FR-TWIG-001, FR-IMP-001 |
| `Resources/views/import_export/import.html.twig` | File upload import UI | FR-TWIG-001, FR-IMP-001 |
| `Resources/views/import_export/_workspace_panel.html.twig` | Workspace export panel | FR-TWIG-001, FR-IMP-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 4 | 4 |
| Symfony config | 2 | 2 |
| Doctrine | 1 | 1 |
| Entities | 9 | 9 |
| Enums | 3 | 3 |
| Repositories | 9 | 9 |
| Controllers | 10 | 10 |
| Forms | 6 | 6 |
| Models | 2 | 2 |
| Services (core) | 9 | 9 |
| ImportExport | 7 | 7 |
| Security + access subscriber | 4 | 4 |
| CLI commands | 2 | 2 |
| Event listeners | 1 | 1 |
| Twig extension | 1 | 1 |
| Translations | 7 | 7 |
| TypeScript source | 8 | 8 |
| Public assets (built) | 7 | 7 |
| Twig views | 25 | 25 |
| **Total production sources** | **117** | **117** |

Built JavaScript under `Resources/public/` is produced from the TypeScript sources listed above; both sides are counted as distinct production artifacts in this inventory.
