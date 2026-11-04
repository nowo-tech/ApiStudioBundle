<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

/**
 * What a user does in Api Studio, as asked to {@see ApiStudioPermissionCheckerInterface}. A checker must deny a case
 * it does not know: cases may be added in later minors.
 */
enum ApiStudioAction: string
{
    /** The tenant's studio: dashboard, workspace list, locale switch. */
    case StudioOpen = 'studio.open';
    /** Create a workspace in the tenant. */
    case WorkspaceCreate = 'workspace.create';
    /** Read a workspace: its pages, docs, examples, the import / export hub. Without it the workspace does not exist (404). */
    case WorkspaceView = 'workspace.view';
    /** Send requests from the console (`/execute/{id}`). */
    case WorkspaceRun = 'workspace.run';
    /** Change services, endpoints, docs, examples, environments and variables. */
    case WorkspaceEdit = 'workspace.edit';
    /** Change pre / post request scripts (code that runs in other users' browsers). */
    case WorkspaceEditScripts = 'workspace.edit_scripts';
    /** Rename, change the slug of or delete the workspace. */
    case WorkspaceManage = 'workspace.manage';
    /** OpenAPI / Postman / variable imports. */
    case WorkspaceImport = 'workspace.import';
    /** OpenAPI exports (no variable values). */
    case WorkspaceExport = 'workspace.export';
    /** Variable exports (secret values in clear). */
    case WorkspaceExportVariables = 'workspace.export_variables';
    /** Other users' request history (`history.scope: user`). */
    case HistoryViewAll = 'history.view_all';

    /**
     * False for the tenant-level actions (studio.open, workspace.create).
     */
    public function needsWorkspace(): bool
    {
        return $this !== self::StudioOpen && $this !== self::WorkspaceCreate;
    }
}
