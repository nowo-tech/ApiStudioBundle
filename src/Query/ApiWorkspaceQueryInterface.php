<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

use Nowo\ApiStudioBundle\Security\ApiWorkspaceAccessInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * 1.19 (DDD-host checklist B1/B7): what a host lists or counts of Api Studio workspaces, without DQL over the bundle's
 * tables and without loading an entity (scalar reads on the bundle's own manager, `nowo_api_studio.entity_manager`).
 *
 * - **A store read, not an access check.** The tenant you pass is the only boundary: no permission or scope checker is
 *   asked. Narrow to what the user may see with {@see ApiWorkspaceCriteria::withIds()} (your own permission port), or
 *   ask {@see ApiWorkspaceAccessInterface} per workspace.
 * - **Exact keys** (house LQ rule): the tenant, the scope and the ids are bound parameters compared in SQL, and every
 *   row is compared again in PHP with `===`, so a look-alike tenant or scope (other case, padded) never matches.
 *   Without `tenancy.enabled` every workspace belongs to the instance tenant `('', '')`; any other tenant matches
 *   nothing. Without `scope.enabled` every workspace is at tenant level.
 * - **No oracle**: an unknown id, an id of another tenant and a look-alike all give the same `null` / nothing.
 * - Inside an Api Studio request the bundle's `nowo_api_studio_tenant` SQL filter (tenancy) is on as well: it can only
 *   narrow the answer to that request's tenant and visible workspaces.
 * - Stateless (FrankenPHP worker mode, kernel reset false); every call reads the store again. An answer is a snapshot:
 *   it may be stale as soon as it is returned (REQ-RUNTIME-003).
 */
interface ApiWorkspaceQueryInterface
{
    /**
     * One page of the workspaces matching $criteria, with the total.
     */
    public function find(ApiWorkspaceCriteria $criteria): ApiWorkspacePage;

    /**
     * Number of workspaces matching $criteria (paging ignored).
     */
    public function count(ApiWorkspaceCriteria $criteria): int;

    /**
     * The workspace $workspaceId of $tenant (any scope), null when there is none.
     */
    public function get(ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView;

    /**
     * Id of the workspace of service $serviceId when that workspace belongs to $tenant, else null.
     */
    public function workspaceIdOfService(ApiStudioTenant $tenant, int $serviceId): ?int;

    /**
     * Id of the workspace of endpoint $endpointId when that workspace belongs to $tenant, else null.
     */
    public function workspaceIdOfEndpoint(ApiStudioTenant $tenant, int $endpointId): ?int;
}
