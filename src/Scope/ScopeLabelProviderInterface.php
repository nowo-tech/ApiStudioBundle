<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Scope;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * Host contract (`nowo_api_studio.scope.label_provider`, 1.17, optional): the badge of scoped workspaces on
 * tenant-level lists (e.g. the project name). Called once per page with every scope on it (no N+1). A missing key
 * shows the raw scope value. Labels are escaped by Twig.
 */
interface ScopeLabelProviderInterface
{
    /**
     * @param list<WorkspaceScope> $scopes
     *
     * @return array<string, string> scope value => label
     */
    public function labels(array $scopes, ApiStudioTenant $tenant): array;
}
