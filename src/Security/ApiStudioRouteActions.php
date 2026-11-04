<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;

/**
 * Every Api Studio route and the action it needs. {@see ApiStudioAccessSubscriber}
 * answers 404 for a `nowo_api_studio_*` route missing here (deny by default), checks `studio.open` on every route and
 * `workspace.create` on the creation page; workspace actions are checked by the controllers once the workspace is
 * loaded ({@see ApiStudioAuthorization::requireWorkspace()}). Public so hosts can map actions onto their own levels and
 * test their coverage.
 */
final class ApiStudioRouteActions
{
    public const MAP = [
        'nowo_api_studio_dashboard'                         => ApiStudioAction::StudioOpen,
        'nowo_api_studio_workspace_index'                   => ApiStudioAction::StudioOpen,
        'nowo_api_studio_locale_switch'                     => ApiStudioAction::StudioOpen,
        'nowo_api_studio_workspace_new'                     => ApiStudioAction::WorkspaceCreate,
        'nowo_api_studio_workspace_show'                    => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_workspace_edit'                    => ApiStudioAction::WorkspaceManage,
        'nowo_api_studio_workspace_delete'                  => ApiStudioAction::WorkspaceManage,
        'nowo_api_studio_service_index'                     => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_service_show'                      => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_service_new'                       => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_service_edit'                      => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_service_delete'                    => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_environment_index'                 => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_environment_show'                  => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_environment_new'                   => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_environment_edit'                  => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_environment_delete'                => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_environment_sync_variables'        => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_index'                    => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_endpoint_show'                     => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_endpoint_new'                      => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_edit'                     => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_delete'                   => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_save_documentation'       => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_examples_save_request'    => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_examples_save_response'   => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_examples_delete_request'  => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_endpoint_examples_delete_response' => ApiStudioAction::WorkspaceEdit,
        'nowo_api_studio_execute'                           => ApiStudioAction::WorkspaceRun,
        'nowo_api_studio_io_hub'                            => ApiStudioAction::WorkspaceView,
        'nowo_api_studio_io_import_openapi'                 => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_import_postman'                 => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_import_variables_workspace'     => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_import_openapi_service'         => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_import_postman_service'         => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_import_variables_environment'   => ApiStudioAction::WorkspaceImport,
        'nowo_api_studio_io_export_openapi_workspace'       => ApiStudioAction::WorkspaceExport,
        'nowo_api_studio_io_export_openapi_service'         => ApiStudioAction::WorkspaceExport,
        'nowo_api_studio_io_export_variables_workspace'     => ApiStudioAction::WorkspaceExportVariables,
        'nowo_api_studio_io_export_variables_environment'   => ApiStudioAction::WorkspaceExportVariables,
    ];

    public static function actionFor(string $route): ?ApiStudioAction
    {
        return self::MAP[$route] ?? null;
    }
}
