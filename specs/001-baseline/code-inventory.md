# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/api-studio-bundle`  
**Last audited**: 2026-11-24 (sweeps: 2026-10-30 baseline, 2026-11-16 and 2026-11-22 later sources, 2026-11-23 import unit)

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and demo trees are out of Packagist scope unless promoted in the spec.

## Bundle & DI (`src/` root + `DependencyInjection/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `ApiStudioBundle.php` | Bundle entry, alias `nowo_api_studio` | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree (`enabled`, `connection`, `table_prefix`, `environments`, `ui.path`, locales) | FR-CFG-001 |
| `DependencyInjection/ApiStudioExtension.php` | DI extension, parameter wiring | FR-CFG-001, FR-DI-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig namespace `@NowoApiStudioBundle` | FR-CFG-001, FR-TWIG-001 |
| `DependencyInjection/Compiler/ApiStudioSecurityPass.php` | Requires SecurityBundle, allowlist policy, always registers the access subscriber (404 when `enabled: false`) | FR-SEC-001, FR-SEC-003 |

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service autowiring and tags | FR-DI-001 |
| `Resources/config/routes.yaml` | Studio HTTP routes | FR-DI-001, FR-CTRL-001 |

## Doctrine (`src/Doctrine/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/TablePrefixSubscriber.php` | Configurable table prefix on ORM metadata; conditional folder (1.14) and workspace / folder script (1.15) mappings | FR-ORM-002, FR-SCR-008 (spec 003) |
| `Doctrine/SecretVariableEncryptionListener.php` | Encrypts secret variable values before flush, decrypts after load/flush | FR-SEC-002 |
| `Doctrine/FolderParentDetachListener.php` | Deletes the folders of deleted services / workspaces in the same flush (1.14, RJ-M2) | FR-FOLD-003 (spec 002) |
| `Service/FolderTreeConflictException.php` | Retryable tree write conflict (deadlock / lost savepoint → 409) (1.14) | FR-FOLD-007 (spec 002) |

## Entities (`src/Entity/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/ApiWorkspace.php` | Workspace root aggregate; pre / post scripts in sandbox mode (1.15) | FR-ORM-001, FR-SCR-008 (spec 003) |
| `Entity/ApiService.php` | Service collection under workspace | FR-ORM-001 |
| `Entity/ApiEndpoint.php` | Endpoint definition (REST/SOAP/GraphQL) | FR-ORM-001 |
| `Entity/ApiFolder.php` | Service folder tree node (1.14): parent, order, inheritable auth / headers, creator id; pre / post scripts in sandbox mode (1.15) | FR-FOLD-003 (spec 002), FR-SCR-008 (spec 003) |
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
| `Repository/ApiFolderRepository.php` | Folder tree rows, endpoint folder ids, levels (1.14) | FR-FOLD-006 (spec 002) |
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
| `Controller/ApiFolderController.php` | Folder CRUD and tree moves (1.14) | FR-FOLD-005, FR-FOLD-007 (spec 002) |
| `Controller/ScriptSandboxController.php` | Sandboxed script frame document with its own CSP (1.15) | FR-SCR-002 (spec 003) |
| `Controller/ScriptSendRequestController.php` | `pm.sendRequest` relay endpoint (sandbox mode, 1.15) | FR-SCR-006 (spec 003) |
| `Script/ScriptRequestSender.php` | Guarded literal sender of `pm.sendRequest` (1.15) | FR-SCR-006 (spec 003) |
| `Script/Runner/ApiStudioScriptRunnerInterface.php` | Server-side script runner port (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptRunRequest.php` | Runner request value object (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptRunResult.php` | Runner result value object (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptSource.php` | Script source value object (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptRunLimits.php` | Runner time / memory / output limits (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptTestResult.php` | Result of one script test case (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ScriptRunStatus.php` | Run status enum (1.15) | FR-SCR-005 (spec 003) |
| `Script/Runner/ProcessScriptRunner.php` | Reference runner: sidecar process per run, limits, sendRequest relay (1.15) | FR-SCR-004, FR-SCR-005 (spec 003) |
| `Script/Runner/NullScriptRunner.php` | Default runner: scripts not run (1.15) | US2 (spec 003) |
| `Script/ScriptChainBuilder.php` | Endpoint script chain in run order with log labels, for runs outside the browser (1.15) | US4 (spec 003) |
| `Script/ScriptVariableStore.php` | `pm.globals` / `pm.collectionVariables` storage, DBAL per key (1.15) | US3 (spec 003) |
| `Controller/ScriptVariablesController.php` | Saves script globals / collection variables (sandbox mode, 1.15) | US3 (spec 003) |
| `Entity/ApiScriptVariable.php` | Script variable of a workspace (global) or service (collection), sandbox mode only (1.15) | US3 (spec 003) |
| `Controller/ApiEnvironmentController.php` | Environment CRUD | FR-CTRL-001 |
| `Controller/EnvironmentVariablesController.php` | Environment variable editor | FR-CTRL-001 |
| `Controller/EndpointExamplesController.php` | Request/response example CRUD | FR-CTRL-001 |
| `Controller/ImportExportController.php` | Import/export hub and actions | FR-CTRL-001, FR-IMP-001 |
| `Controller/ApiExecuteController.php` | In-browser request execution API | FR-CTRL-001, FR-EXEC-001 |
| `Controller/LocaleController.php` | Locale switcher route | FR-CTRL-001, FR-I18N-001 |
| `Controller/ApiStudioPageTrait.php` | Page factory, rendering with the shared page variables, translated flashes, CSRF-only delete forms of the confirm contract | FR-CTRL-001, FR-UI-003 |
| `Controller/CsrfOnlyFormValidationTrait.php` | Validates CSRF-only POSTs (delete buttons) through `CsrfOnlyType` | FR-CTRL-001, FR-SEC-001 |

## Forms (`src/Form/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/ApiWorkspaceFormType.php` | Workspace create/edit form | FR-FORM-001 |
| `Form/ApiServiceFormType.php` | Service create/edit form | FR-FORM-001 |
| `Form/ApiEndpointFormType.php` | Endpoint create/edit form | FR-FORM-001 |
| `Form/ApiFolderFormType.php` | Folder form type (1.14) | FR-FOLD-005 (spec 002) |
| `Form/ApiEnvironmentFormType.php` | Environment create/edit form | FR-FORM-001 |
| `Form/ApiEndpointTranslationFormType.php` | Per-locale endpoint title/description/notes sub-form | FR-FORM-001, FR-I18N-001 |
| `Form/ApiEnvironmentVariableFormType.php` | Environment variable row (key, value, secret flag) | FR-FORM-001, FR-SEC-002 |
| `Form/JsonMapType.php` | Key/value JSON map field | FR-FORM-001 |
| `Form/ImportFileFormType.php` | OpenAPI/Postman upload form | FR-FORM-001, FR-IMP-001 |
| `Form/ClientSideFieldsType.php` | Unmapped client-side controls (tree filter, format radios) rendered as form views | FR-FORM-001 |
| `Form/CsrfOnlyType.php` | Root-less CSRF-only POST form (flat `_token`) for deletes | FR-FORM-001, FR-SEC-001 |
| `Form/KeyValueRowType.php` | Header / query parameter row of the request console | FR-FORM-001, FR-EXEC-004 |

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
| `Service/FolderTree.php` | Folder depth / cycle rules, moves, reorders, deletes (1.14) | FR-FOLD-002, FR-FOLD-007 (spec 002) |
| `Service/FolderInheritanceResolver.php` | Headers and auth inherited from folders (1.14) | FR-FOLD-008 (spec 002) |
| `Service/HistorySanitizer.php` | Redacts secrets/PII from history URL, headers and bodies before persistence | FR-OBS-001 |
| `Service/WorkspaceEnvironmentInitializer.php` | Seeds a new workspace with the configured `environments` (first = default) | FR-CFG-001, FR-ORM-003 |
| `Service/ResponseBodyReader.php` | Response body read within `execution_max_response_bytes` (REST, SOAP, WSDL) | FR-EXEC-001, FR-EXEC-003 |
| `Service/EndpointTesterFormFactory.php` | Builds the request console form view (environment, method, URL, body, scripts, params, headers) | FR-FORM-001, FR-EXEC-001 |

## Services — ImportExport (`src/Service/ImportExport/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/ImportExport/DocumentParser.php` | Detect OpenAPI vs Postman format | FR-IMP-002 |
| `Service/ImportExport/SlugHelper.php` | Unique slug generation on import | FR-IMP-002 |
| `Service/ImportExport/OpenApiImporter.php` | OpenAPI → entities merge | FR-IMP-001 |
| `Service/ImportExport/PostmanCollectionImporter.php` | Postman collection → entities merge | FR-IMP-001 |
| `Service/ImportExport/PostmanAuthMapper.php` | Postman auth objects → Api Studio auth (1.14) | FR-FOLD-009 (spec 002) |
| `Service/ImportExport/FolderImportHelper.php` | Per-import folder lookup / creation with depth cap (1.14) | FR-FOLD-009, FR-FOLD-010 (spec 002) |
| `Service/ImportExport/OpenApiExporter.php` | Entities → OpenAPI document | FR-IMP-001 |
| `Service/ImportExport/EnvironmentVariableExporter.php` | Variables → export file | FR-IMP-001 |
| `Service/ImportExport/EnvironmentVariableImporter.php` | Import file → variables | FR-IMP-001 |

## Security (`src/Security/` + access subscriber)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ApiStudioAccessCheckerInterface.php` | Host-app access policy contract | FR-SEC-001 |
| `Security/ConfigurableApiStudioAccessChecker.php` | Default role/config checker | FR-SEC-001 |
| `Security/FolderAccess.php` | Folder decisions over the host folder checker (1.14) | FR-FOLD-011 (spec 002) |
| `Security/ApiStudioFolderPermissionCheckerInterface.php` | Host folder sharing SPI (1.14) | FR-FOLD-011 (spec 002) |
| `Security/ApiStudioFolderRef.php` | Folder ids VO for the folder SPI (1.14) | FR-FOLD-011 (spec 002) |
| `Security/ExecutionUrlValidator.php` | SSRF / open-redirect guard | FR-EXEC-003 |
| `Security/SecretValueCipher.php` | Sodium secretbox cipher for secret variable values at rest | FR-SEC-002 |
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
| `EventListener/EntityManagerRecoverySubscriber.php` | Resets a closed Api Studio entity manager at request start (worker mode) | FR-ORM-003 |

## Twig extensions (`src/Twig/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/ApiStudioExtension.php` | Nav tree, locales / layout globals, method CSS, variable helpers | FR-TWIG-001 |
| `Twig/ApiStudioFormsExtension.php` | Form views for templates: CSRF-only form, tree filter, service dialog forms | FR-TWIG-001, FR-FORM-001 |

## UiKit page contract (`src/Ui/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Ui/ApiStudioPages.php` | Layout resolution (`ui.layout_template` / `nowo_ui_kit.layouts.admin`), deprecated pre-1.10 layout bridge, labels, `ui.header_actions` (1.23) | FR-UI-003, FR-CFG-001 |
| `Ui/ApiStudioPageFactory.php` | UiKit view models of every page (headers, actions, breadcrumbs, rows, sections, delete zone); `header` or `page_actions` (UiKit split header) create actions (1.23) | FR-UI-003 |

## SOAP transport (`src/Soap/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Soap/GuardedSoapClient.php` | `SoapClient` whose transport is the guarded Symfony HTTP client (endpoint checked by `ExecutionUrlValidator`) | FR-EXEC-001, FR-EXEC-005 |
| `Soap/GuardedWsdlLoader.php` | Downloads WSDL/XSD imports through the guarded client into local copies; rejects DTDs | FR-EXEC-003, FR-EXEC-005 |
| `Soap/SoapEnvelope.php` | Raw-envelope SOAP helpers (no ext-soap / ext-dom needed) | FR-EXEC-005 |

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
| `Resources/assets/src/api-studio-shell.ts` | Workbench: API tree, service collections, service dialogs (UiKit `<dialog>`), console sub-tabs | FR-UI-001, FR-UI-003 |
| `Resources/assets/src/api-body-tools.ts` | Request body editor helpers | FR-UI-001, FR-EXEC-004 |
| `Resources/assets/src/api-script-runtime.ts` | Pre/post-request script runner | FR-UI-001 |
| `Resources/assets/src/api-endpoint-doc.ts` | Endpoint documentation panel | FR-UI-001 |
| `Resources/assets/src/lib/utils.ts` | Shared DOM/HTTP utilities | FR-UI-001 |
| `Resources/assets/src/lib/script-approval.ts` | Stored-script approval (`scripts.confirm_stored`, SHA-256 per session) | FR-UI-001 |
| `Resources/assets/src/lib/pm.ts` | Shared `pm` facade and script runner (inline + sandbox, 1.15) | FR-UI-001, FR-SCR-007 (spec 003) |
| `Resources/assets/src/lib/expect.ts` | chai BDD subset of `pm.expect` (1.15) | FR-SCR-007 (spec 003) |
| `Resources/assets/src/lib/sandbox.ts` | Sandboxed runner protocol, frame execution, page runner (1.15) | FR-SCR-001..004 (spec 003) |
| `Resources/assets/src/api-script-worker.ts` | Script worker of the sandbox frame: runs the scripts, no network, nothing to navigate (1.15, audit RW-M1) | FR-SCR-002 (spec 003) |
| `Resources/assets/src/lib/frame-relay.ts` | Sandbox frame relay between the console and the script worker (1.15, audit RW-M1) | FR-SCR-003 (spec 003) |
| `Resources/assets/src/lib/node-refusal.ts` | Server runner refuses Node without a network block (1.15, audit RW-L1) | FR-SCR-005 (spec 003) |
| `Resources/assets/src/lib/lockdown.ts` | Removes the network APIs from the sandbox frame before scripts run (1.15) | FR-SCR-002 (spec 003) |
| `Resources/assets/src/lib/require.ts` | Closed `require()` library allow-list, frame and runner only (1.15) | FR-SCR-007 (spec 003) |
| `Resources/assets/src/api-script-runner.ts` | Server runner sidecar entry, Deno / Node, line protocol (1.15) | FR-SCR-005 (spec 003) |
| `Resources/runner/api-script-runner.js` | Built server runner sidecar (1.15) | FR-SCR-005 (spec 003) |
| `Resources/assets/src/api-script-sandbox.ts` | Frame runtime of `scripts.mode: sandbox` (1.15) | FR-SCR-002 (spec 003) |
| `Resources/assets/src/types/global.d.ts` | Window/global type declarations | FR-UI-001 |

## Public assets — built (`src/Resources/public/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/public/api-tester.js` | Built tester bundle | FR-UI-001 |
| `Resources/public/api-studio-shell.js` | Built shell bundle | FR-UI-001 |
| `Resources/public/api-body-tools.js` | Built body-tools bundle | FR-UI-001 |
| `Resources/public/api-script-runtime.js` | Built script-runtime bundle | FR-UI-001 |
| `Resources/public/api-script-sandbox.js` | Built sandbox frame runtime (1.15) | FR-SCR-002 (spec 003) |
| `Resources/public/api-endpoint-doc.js` | Built endpoint-doc bundle | FR-UI-001 |
| `Resources/public/api-studio-theme.css` | Workbench / console stylesheet on the UiKit tokens | FR-UI-002 |

## Twig views (`src/Resources/views/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/_legacy_layout.html.twig` | Deprecated bridge around a pre-1.10 host layout | FR-UI-003 |
| `Resources/views/_workbench.html.twig` | Workbench: API tree beside the page content | FR-TWIG-001, FR-UI-003 |
| `Resources/views/_styles.html.twig` | Api Studio stylesheet tag (`nowo_ui_styles`) | FR-UI-003 |
| `Resources/views/_scripts.html.twig` | Api Studio script tags (deferred, CSP nonce) | FR-UI-003 |
| `Resources/views/_sidebar_tree.html.twig` | API tree (one query) and client-side filter | FR-TWIG-001 |
| `Resources/views/_sidebar_endpoints.html.twig` | Endpoint list in sidebar | FR-TWIG-001 |
| `Resources/views/_sidebar_folders.html.twig` | Nested folders of the API tree (1.14) | FR-FOLD-006 (spec 002) |
| `Resources/views/_variable_catalog.html.twig` | Environment variable reference | FR-TWIG-001, FR-EXEC-002 |
| `Resources/views/_endpoint_expandable_list.html.twig` | Collapsible endpoint list | FR-TWIG-001 |
| `Resources/views/_service_collection_list.html.twig` | Service listing partial | FR-TWIG-001 |
| `Resources/views/_service_kebab_menu.html.twig` | Service actions through the UiKit kebab | FR-TWIG-001, FR-UI-003 |
| `Resources/views/_service_action_modals.html.twig` | Service export / import / type-to-confirm delete dialogs (UiKit `<dialog>`) | FR-TWIG-001, FR-UI-003 |
| `Resources/views/dashboard/index.html.twig` | Dashboard overview | FR-TWIG-001 |
| `Resources/views/workspace/index.html.twig` | Workspace index | FR-TWIG-001 |
| `Resources/views/workspace/form.html.twig` | Workspace form | FR-TWIG-001, FR-FORM-001 |
| `Resources/views/workspace/show.html.twig` | Workspace detail | FR-TWIG-001 |
| `Resources/views/service/index.html.twig` | Service index | FR-TWIG-001 |
| `Resources/views/service/form.html.twig` | Service form | FR-TWIG-001, FR-FORM-001 |
| `Resources/views/folder/form.html.twig` | Folder form (1.14) | FR-FOLD-005 (spec 002) |
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

## Sources added after the last baseline review (sweep 2026-11-16)

These 53 `src/` files were added by releases after the last review. Each row maps the file to the requirement already used for its neighbours in the same directory; the description comes from the class docblock. With them, every file under `src/` is listed in this inventory.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/SecretsStatusCommand.php` | Class `SecretsStatusCommand` | FR-CLI-001 |
| `Command/TenantCommandTrait.php` | Shared checks of the `nowo:api-studio:tenant:*` commands: tenancy must be on and the workspaces must be ORM-managed. | FR-CLI-001 |
| `Command/TenantMoveCommand.php` | Class `TenantMoveCommand` | FR-CLI-001 |
| `Command/TenantOrphansCommand.php` | Class `TenantOrphansCommand` | FR-CLI-001 |
| `Command/TenantUnsuffixSlugsCommand.php` | Class `TenantUnsuffixSlugsCommand` | FR-CLI-001 |
| `Controller/AuthorizesTrait.php` | The per-workspace, per-action decisions (ApiStudioAuthorization) from the controller's service locator. | FR-CTRL-001 |
| `Controller/SlugConflictTrait.php` | Race safety net of the slug validation (UniqueSlug): when another request takes the same slug between validation and... | FR-CTRL-001 |
| `Controller/StudioEventsTrait.php` | Bundle events of the Api Studio controllers (1.12): dispatched after the write was flushed, with ids and the opaque a... | FR-CTRL-001 |
| `DependencyInjection/Compiler/ApiStudioEntityManagerPass.php` | `nowo_api_studio.entity_manager` (1.12, architecture bar rule 6): maps the Api Studio entities on that manager only. | FR-CFG-001 |
| `Doctrine/ApiStudioEntityManagerResolver.php` | The entity manager of the Api Studio entities (1.12, architecture bar rule 6): `nowo_api_studio.entity_manager` when... | FR-ORM-002 |
| `Doctrine/ExcludedEntityNamespaceDriver.php` | Metadata driver that maps nothing: registered for the Api Studio entity namespace on every entity manager other than... | FR-ORM-002 |
| `Doctrine/TenantSqlFilter.php` | Defence in depth for tenancy (`nowo_api_studio_tenant`): during an Api Studio request every bundle query on a workspa... | FR-ORM-002 |
| `Event/ApiRequestExecutedEvent.php` | Dispatched after the studio executed a request and stored its history row (1.12, architecture bar rules 4 and 7). | FR-EVENT-001 |
| `Event/ApiStudioResourceChangedEvent.php` | Dispatched after the studio created, updated, deleted or imported a resource and the change was flushed (1.12, archit... | FR-EVENT-001 |
| `Event/ApiWorkspaceCreatedCommittedEvent.php` | Dispatched once a workspace created from the studio is **committed** (1.12, architecture bar rule 7): ids only, for o... | FR-EVENT-001 |
| `Event/ApiWorkspaceCreatedEvent.php` | Dispatched when a workspace is created from the studio, after its flush and **inside** the same database transaction:... | FR-EVENT-001 |
| `Event/ApiWorkspaceDeletedEvent.php` | Dispatched after a workspace was deleted from the studio (informational: the bundle's own rows are gone through forei... | FR-EVENT-001 |
| `Event/ApiWorkspaceMovedEvent.php` | Dispatched by `nowo:api-studio:tenant:move` after a workspace moved to another tenant (host rows, caches). | FR-EVENT-001 |
| `EventListener/EntityManagerBoundarySubscriber.php` | Since 1.12.1 (REQ-RUNTIME-002 rule 4): unit-of-work boundaries of the Api Studio entity manager when it is bundle-own... | FR-I18N-001 |
| `Form/EventListener/SlugFromName.php` | An empty slug is derived from the submitted name (1.11): lowercase, other characters as `_`. | FR-EVENT-001 |
| `Model/HostSecretResolution.php` | Outcome of resolving the host secret references of one execution (1.13). | FR-EXEC-001 |
| `Resources/assets/tests/sidebar-tree.test.ts` | Vitest test `sidebar-tree.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/tester-scripts-disabled.test.ts` | Vitest test `tester-scripts-disabled.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/body-tools.test.ts` | Vitest test `body-tools.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/endpoint-doc.test.ts` | Vitest test `endpoint-doc.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/expect.test.ts` | Vitest test `expect.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/frame-relay.test.ts` | Vitest test `frame-relay.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/lockdown.test.ts` | Vitest test `lockdown.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/logger.test.ts` | Vitest test `logger.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/node-refusal.test.ts` | Vitest test `node-refusal.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/require.test.ts` | Vitest test `require.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/sandbox-hardening.test.ts` | Vitest test `sandbox-hardening.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/sandbox.test.ts` | Vitest test `sandbox.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-approval.test.ts` | Vitest test `script-approval.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-loaded.test.ts` | Vitest test `script-loaded.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-runner.test.ts` | Vitest test `script-runner.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-runtime.test.ts` | Vitest test `script-runtime.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-sandbox-frame.test.ts` | Vitest test `script-sandbox-frame.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/script-worker.test.ts` | Vitest test `script-worker.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/studio-shell.test.ts` | Vitest test `studio-shell.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/tester-console.test.ts` | Vitest test `tester-console.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/tester-sandbox.test.ts` | Vitest test `tester-sandbox.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/tester-scripts-confirm.test.ts` | Vitest test `tester-scripts-confirm.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/tree-dnd.test.ts` | Vitest test `tree-dnd.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/tests/utils.test.ts` | Vitest test `utils.test.ts` (ships no runtime code) | FR-UI-001 |
| `Resources/assets/src/logger.ts` | Console logger of the bundle scripts: `log.scriptLoaded()` banner (REQ-ASSETS-005) and debug-gated level methods | FR-UI-001 |
| `Routing/ApiStudioRouteLoader.php` | Route loader behind ` | FR-ROUTING-001 |
| `Routing/ApiStudioRouteScopeListener.php` | `ui.path` placeholders (e.g. `/organizations/{organization}/api-studio`): on an Api Studio request the values of the... | FR-ROUTING-001 |
| `Secret/ApiStudioSecretRef.php` | A host secret reference to resolve (1.13, ApiStudioSecretSourceInterface), with the context the host authorises it in. | FR-SECRET-001 |
| `Secret/ApiStudioSecretSourceInterface.php` | Host contract (`nowo_api_studio.secrets.source`, 1.13): resolves a host secret that a secret environment variable ref... | FR-SECRET-001 |
| `Secret/ApiStudioSecretValue.php` | A resolved host secret (1.13, ApiStudioSecretSourceInterface). | FR-SECRET-001 |
| `Secret/RequestOrigin.php` | The origin of a request URL (1.13, QE-B-M1): `scheme://host[:port]`, lower case, default port omitted; null when the... | FR-SECRET-001 |
| `Secret/SecretForms.php` | Encodings of a resolved host secret a target may echo back (1.13, QE-B-L1), masked next to the raw, URL-encoded and J... | FR-SECRET-001 |
| `Secret/SecretRedactingLogger.php` | Masks resolved host secrets in the message and the string context values of every record before it reaches the host l... | FR-SECRET-001 |
| `Secret/SecretTargetChangedException.php` | A request carrying host secrets would leave the origin they were resolved for (1.13, QE-D-L1: a WSDL naming a `soap:a... | FR-SECRET-001 |
| `Security/ApiStudioAction.php` | What a user does in Api Studio, as asked to ApiStudioPermissionCheckerInterface. | FR-SEC-001 |
| `Security/ApiStudioActorResolverInterface.php` | Host contract (`nowo_api_studio.history.actor_resolver`): the opaque id stored as `executed_by` on each history row w... | FR-SEC-001 |
| `Security/ApiStudioAuthorization.php` | Per-workspace, per-action decisions of the current request: tenant scope (ApiStudioScope) + the host's ApiStudioPermi... | FR-SEC-001 |
| `Security/ApiStudioHistoryScope.php` | `nowo_api_studio.history.scope`: `endpoint` (default, 1.10 behaviour) = one history per endpoint, shared; `user` = ev... | FR-SEC-001 |
| `Security/ApiStudioPermissionCheckerInterface.php` | Host contract (`nowo_api_studio.security.permission_checker`): who may do what, per workspace. | FR-SEC-001 |
| `Security/ApiStudioRouteActions.php` | Every Api Studio route and the action it needs. | FR-SEC-001 |
| `Security/ApiStudioScope.php` | The workspaces the current request may see: those of the tenant (when tenancy is on), narrowed to `visibleIds` when t... | FR-SEC-001 |
| `Security/ApiStudioSubject.php` | What an ApiStudioAction applies to: the tenant, and the workspace for workspace actions. | FR-SEC-001 |
| `Security/LegacyPermissionChecker.php` | Default checker (no `security.permission_checker`): whoever passed the role check and the legacy access checker may d... | FR-SEC-001 |
| `Service/HostSecretResolver.php` | Resolves the host secret references (`{{secret:<source>/<id>}}`) stored in secret environment variables through the h... | FR-SECRET-001 |
| `Tenancy/ApiStudioTenant.php` | Opaque owner of a workspace: a `(type, id)` pair chosen by the host (e.g. `('organization', <public uuid>)`). | FR-TENANCY-001 |
| `Tenancy/ApiStudioTenantContext.php` | The tenant of the work being done: the one ApiStudioTenantListener resolved for the current main request, else the on... | FR-TENANCY-001 |
| `Tenancy/ApiStudioTenantListener.php` | With tenancy on, resolves the tenant of every Api Studio main request (`nowo_api_studio_*`) once, right after the rou... | FR-TENANCY-001 |
| `Tenancy/ApiStudioTenantProviderInterface.php` | Host contract (`nowo_api_studio.tenancy.provider`, 1.12): the current tenant, without an HTTP request — for hosts who... | FR-TENANCY-001 |
| `Tenancy/ApiStudioTenantResolverInterface.php` | Host contract (`nowo_api_studio.tenancy.resolver`): the tenant of an Api Studio request. | FR-TENANCY-001 |
| `Tenancy/TenantProviderResolver.php` | Adapts a request-free ApiStudioTenantProviderInterface (`tenancy.provider`, 1.12) to the resolver the tenant listener... | FR-TENANCY-001 |
| `Tenancy/TenantSqlFilterListener.php` | With `tenancy.enabled` and `tenancy.sql_filter` (default true): enables TenantSqlFilter with the request's scope once... | FR-TENANCY-001 |
| `Validator/SlugFormat.php` | Slug format (1.11): lowercase letters and digits, single `-` or `_` between them, at most 128 characters. | FR-VALIDATOR-001 |
| `Validator/SlugFormatValidator.php` | Applies SlugFormat to a new slug or a changed one (compared with the value Doctrine loaded). | FR-VALIDATOR-001 |
| `Validator/UniqueSlug.php` | The entity's slug is unique within its scope: the instance for a workspace, the workspace for a service or an environ... | FR-VALIDATOR-001 |
| `Validator/UniqueSlugValidator.php` | Looks the slug up in the entity's scope (UniqueSlug::$scope) and reports a duplicate on the `slug` field. | FR-VALIDATOR-001 |

## Sources added in 1.17–1.19 (sweep 2026-11-22)

These 45 `src/` files (workspace seeding, scopes, global search, workspace query port, conflict subscriber, URL generator) were added after the 2026-11-16 sweep. Each row names the area requirement in `spec.md`; the description comes from the class docblock.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Bridge/SearchKit/ApiStudioSearchDefinition.php` | One SearchKit group of Api Studio (1.18): workspaces or services by name, endpoints by name, path and HTTP method. | FR-SEARCH-001 |
| `Event/ApiStudioSearchContextEvent.php` | Host hook of the global search (1.18), dispatched once per group before anything is read: where the search runs and what it may return. | FR-SEARCH-001 |
| `Event/ApiWorkspaceScopeChangedEvent.php` | Dispatched by `WorkspaceScopeMover` once a workspace's scope change is **committed** (1.17): ids only. | FR-SCOPE-001 |
| `Event/ApiWorkspaceSeededEvent.php` | Dispatched by `WorkspaceSeeder` once a seed is **committed** (1.17): ids only, never a variable value. | FR-SEED-001 |
| `EventListener/ConcurrencyConflictSubscriber.php` | Lock conflicts on Api Studio routes (1.17, REQ-RUNTIME-003 rule 6): a deadlock, lock wait timeout or serialization failure anywhere in the failure (or a `Transi | FR-CONCUR-001 |
| `Query/ApiWorkspaceCriteria.php` | 1.19 (DDD-host checklist B7): what `ApiWorkspaceQueryInterface` lists or counts. | FR-QUERY-001 |
| `Query/ApiWorkspacePage.php` | 1.19 (DDD-host checklist B7): one page of `ApiWorkspaceQueryInterface::find()` and the total of the criteria. | FR-QUERY-001 |
| `Query/ApiWorkspaceQueryInterface.php` | 1.19 (DDD-host checklist B1/B7): what a host lists or counts of Api Studio workspaces, without DQL over the bundle's tables and without loading an entity (scala | FR-QUERY-001 |
| `Query/ApiWorkspaceSort.php` | 1.19 (DDD-host checklist B7): the order of `ApiWorkspaceQueryInterface::find()`. | FR-QUERY-001 |
| `Query/ApiWorkspaceView.php` | 1.19 (DDD-host checklist B7): a workspace as scalars, never the entity. | FR-QUERY-001 |
| `Query/DoctrineApiWorkspaceQuery.php` | 1.19 (DDD-host checklist B7): `ApiWorkspaceQueryInterface` on the bundle's entity manager. | FR-QUERY-001 |
| `Resources/assets/src/types/vite-env.d.ts` | Vite client type reference for the TypeScript sources | FR-UI-001 |
| `Routing/ApiStudioUrlGenerator.php` | The URL generator of the bundle's own links (1.17): on a request mounted under `scope.path` a bundle route (`nowo_api_studio_*`) becomes its twin (`nowo_api_stu | FR-ROUTING-001 |
| `Routing/HostRoutesCacheWarmer.php` | 1.16 (audit SY-L1): every `security.host_routes` key must name a route of the router. | FR-ROUTING-001 |
| `Scope/ScopeAccessCheckerInterface.php` | Host contract (`nowo_api_studio.scope.access_checker`, 1.17, mandatory with scopes on): who may do what in a scope. | FR-SCOPE-001 |
| `Scope/ScopeAccessSubject.php` | What a scope decision applies to (1.17): the tenant, the scope and — for workspace actions — the workspace id. | FR-SCOPE-001 |
| `Scope/ScopeContext.php` | The host scope of the work being done (1.17): the one `ScopeListener` resolved for the current main request, else the one set by `self::runAs()` (comm | FR-SCOPE-001 |
| `Scope/ScopeLabelProviderInterface.php` | Host contract (`nowo_api_studio.scope.label_provider`, 1.17, optional): the badge of scoped workspaces on tenant-level lists (e.g. | FR-SCOPE-001 |
| `Scope/ScopeListener.php` | With `scope.enabled` (1.17), resolves the host scope of every Api Studio main request once, right after the tenant, and stores it on the request; a `scope.path` route without a scope answers 404. | FR-SCOPE-001 |
| `Scope/ScopeResolverInterface.php` | Host contract (`nowo_api_studio.scope.resolver`, 1.17): the scope of an Api Studio request, from its route (e.g. | FR-SCOPE-001 |
| `Scope/ScopeRouteContext.php` | The matched route of an Api Studio request, as `ScopeResolverInterface` sees it (1.17): no HTTP type in the port (REQ-ARCH-001 rule 3). | FR-SCOPE-001 |
| `Scope/WorkspaceScope.php` | Opaque host sub-scope of a workspace (1.17, `nowo_api_studio.scope`), e.g. | FR-SCOPE-001 |
| `Scope/WorkspaceScopeMover.php` | The host changes the scope of a workspace (1.17), e.g. | FR-SCOPE-001 |
| `Scope/WorkspaceSlugTakenException.php` | The target (tenant, scope) of `WorkspaceScopeMover::move()` already has a workspace with that slug (1.17). | FR-SCOPE-001 |
| `Search/SearchAccess.php` | Batched per-result checks of the global search (1.18), after the SQL filter: `workspace.view` through `ApiStudioAuthorization::can()` (the tenant and scope | FR-SEARCH-001 |
| `Search/SearchContextResolver.php` | Where a global search runs (1.18): the tenant (`tenancy.resolver` / `provider`), the workspace scope of the search scope id, then the host's {@see ApiStudioSear | FR-SEARCH-001 |
| `Search/SearchHit.php` | One result of the global search (1.18), as plain text: names, the method and the path without its query string, the workspace's scope label. | FR-SEARCH-001 |
| `Search/SearchHitMapper.php` | Turns the scalar rows of the search queries into `SearchHit`s (1.18): workspace / service / endpoint names, the service protocol, the endpoint method and p | FR-SEARCH-001 |
| `Search/SearchKind.php` | The three groups of the global search (1.18): workspaces and services by name, endpoints by name, path and HTTP method. | FR-SEARCH-001 |
| `Security/ApiStudioAccessPolicy.php` | `security.access_denied_response` (1.16): how a refused Api Studio request answers. | FR-SEC-001 |
| `Security/ApiStudioFeatureGateInterface.php` | Host contract (`nowo_api_studio.security.feature_gate`, 1.16): whether Api Studio is available at all for this tenant and user — typically a plan / billing feat | FR-SEC-001 |
| `Security/ApiStudioRoutes.php` | Which routes are Api Studio routes (1.16): the bundle's own (`nowo_api_studio_*`, actions in `ApiStudioRouteActions`) plus the host pages declared in `secu | FR-SEC-001 |
| `Security/ApiWorkspaceAccess.php` | 1.19 (DDD-host checklist B1/B8): `ApiWorkspaceAccessInterface` on `ApiWorkspaceQueryInterface` and the host's checkers. | FR-SEC-001 |
| `Security/ApiWorkspaceAccessInterface.php` | 1.19 (DDD-host checklist B1/B8): whether a user may do a workspace action, asked by ids. | FR-SEC-001 |
| `Seed/EndpointSeed.php` | An endpoint of a `ServiceSeed` (1.17), matched by slug within its service. | FR-SEED-001 |
| `Seed/EnvironmentSeed.php` | An environment of a `WorkspaceSeed` (1.17), matched by slug within the workspace. | FR-SEED-001 |
| `Seed/SecretReferenceSeed.php` | A secret environment variable of an `EnvironmentSeed` (1.17) that holds a host secret **reference** by name, never a value: stored as `{{secret:<source>/<i | FR-SEED-001 |
| `Seed/SeedStats.php` | Counters of one seed attempt (created / updated rows per kind). | FR-SEED-001 |
| `Seed/SeedValidation.php` | Shared checks of the seed value objects (1.17). | FR-SEED-001 |
| `Seed/ServiceSeed.php` | A service of a `WorkspaceSeed` (1.17), matched by slug within the workspace. | FR-SEED-001 |
| `Seed/VariableSeed.php` | A plain (non-secret) environment variable of an `EnvironmentSeed` (1.17), matched by key. | FR-SEED-001 |
| `Seed/WorkspaceSeed.php` | What `WorkspaceSeeder` creates or updates (1.17): one workspace, matched by (tenant, scope, slug), with its services, endpoints, environments, plain variab | FR-SEED-001 |
| `Seed/WorkspaceSeedResult.php` | Outcome of `WorkspaceSeeder::seed()` (1.17): ids and counts only, never a value. | FR-SEED-001 |
| `Seed/WorkspaceSeeder.php` | Creates or updates one workspace from a `WorkspaceSeed` (1.17): idempotent by natural keys — workspace by (tenant, scope, slug), service and environment by | FR-SEED-001 |
| `Twig/ApiStudioRoutingExtension.php` | 1.17 Twig functions of the workspace scopes: - `api_studio_path(name, params)`: `path()` for bundle routes that stays inside a `scope.path` mount (the bundle's  | FR-TWIG-001 |

## Sources added in 1.20 (row versions of the edit forms)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/RowVersionedInterface.php` | A row whose edit form is guarded by its `row_version` (workspace, service, endpoint, environment) | FR-CONCUR-002 |
| `Entity/RowVersionTrait.php` | The `row_version` column (`INTEGER NOT NULL DEFAULT 1`, additive) and its accessors | FR-CONCUR-002 |
| `Concurrency/StaleEditException.php` | An edit saved on a version that is no longer current; a domain conflict (409 + `X-Error-Code: API_STUDIO_STALE_EDIT`), not a lock conflict | FR-CONCUR-002 |
| `Doctrine/RowVersionGuard.php` | Guarded claim (`row_version = row_version + 1 … AND row_version = ?`) and atomic bump of the version | FR-CONCUR-002 |
| `Doctrine/RowVersionListener.php` | Moves the version on every other ORM write of a versioned row and of its translations / variables | FR-CONCUR-002 |
| `Form/RowVersionField.php` | Hidden, unmapped `rowVersion` field of the edit forms (option `row_version`) | FR-CONCUR-002, FR-FORM-001 |
| `Doctrine/DefaultEnvironmentSwitch.php` | A new default environment (edit page, and since 1.21.1 the create page) locks the workspace row, then clears the other defaults in one statement (audit ZH-L1) | FR-CONCUR-002 |
| `Doctrine/WorkspaceImportUnit.php` | 1.21.1 (internal): an import as one write unit under the workspace, service and environment locks, savepoint-aware repair, which failures (lock conflicts only) answer the retryable 409 | FR-CONCUR-002 |
| `Controller/RowVersionEditTrait.php` | Edit save in one transaction (claim + flush), the stale answer (re-read rows, domain 409 + `X-Error-Code`) | FR-CONCUR-002, FR-CTRL-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 5 | 5 |
| Symfony config | 2 | 2 |
| Doctrine | 2 | 2 |
| Entities | 9 | 9 |
| Enums | 3 | 3 |
| Repositories | 9 | 9 |
| Controllers | 12 | 12 |
| Forms | 11 | 11 |
| Models | 2 | 2 |
| Services — core | 13 | 13 |
| Services — ImportExport | 7 | 7 |
| Security | 5 | 5 |
| CLI commands | 2 | 2 |
| Event listeners | 2 | 2 |
| Twig extensions | 2 | 2 |
| UiKit page contract | 2 | 2 |
| SOAP transport | 3 | 3 |
| Translations | 7 | 7 |
| TypeScript source | 7 | 7 |
| Public assets — built | 6 | 6 |
| Twig views | 27 | 27 |
| Sources added after the baseline review (sweeps 2026-11-16 and 2026-11-22, incl. grouped rows) | 140 | 140 |
| Sources added in 1.20 (row versions of the edit forms) | 8 | 8 |
| Sources added in 1.21.1 (workspace import unit) | 1 | 1 |
| Sources added in 1.23 (`logger.ts` listed, runner value objects split one per row) | 1 | 1 |
| **Total production sources** | **288** | **288** |
| Vitest tests under `Resources/assets/tests/` (ship no runtime code, not in the production total) | 24 | 24 |

Built JavaScript under `Resources/public/` is produced from the TypeScript sources listed above; both sides are counted as distinct production artifacts in this inventory.

Audit command (production count): `find src -type f ! -name '*.test.ts' | wc -l` → **288** (2026-11-24, 1.23.0; 287 on 2026-11-23, 1.21.1; 286 on 2026-11-22 after 1.20.0; 278 at 1.19.1, 138 on 2026-10-30, 191 on 2026-11-16).
