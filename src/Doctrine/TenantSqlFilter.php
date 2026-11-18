<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;

use function implode;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Defence in depth for tenancy (`nowo_api_studio_tenant`): during an Api Studio request every bundle query on a
 * workspace, or on a row that hangs off one (service, environment, endpoint, variable, examples, translations,
 * history, folders), is narrowed to the request's tenant and visible workspaces. The explicit checks of the controllers stay
 * the control: SQL filters do not apply to entities already in the identity map, DBAL queries or DQL UPDATE / DELETE.
 * Enabled by {@see TenantSqlFilterListener}; off everywhere else.
 *
 * 1.17: also narrows by the host scope (`scope.enabled`), with or without tenancy.
 */
final class TenantSqlFilter extends SQLFilter
{
    public const NAME = 'nowo_api_studio_tenant';

    /** Unprefixed table name of each bundle entity (the prefix is read from the queried table). */
    private const TABLES = [
        ApiWorkspace::class           => 'workspace',
        ApiService::class             => 'service',
        ApiEnvironment::class         => 'environment',
        ApiEndpoint::class            => 'endpoint',
        ApiEnvironmentVariable::class => 'environment_variable',
        ApiRequestExample::class      => 'request_example',
        ApiResponseExample::class     => 'response_example',
        ApiEndpointTranslation::class => 'endpoint_translation',
        ApiRequestHistory::class      => 'request_history',
        ApiFolder::class              => 'folder',
    ];

    /** 1.17 scope modes of the filter (`scope.enabled`). */
    private const SCOPE_OFF      = 'off';
    private const SCOPE_EXACT    = 'exact';
    private const SCOPE_UNSCOPED = 'unscoped';
    private const SCOPE_VISIBLE  = 'visible';
    private const SCOPE_ALL      = 'all';

    public static function configure(self $filter, ApiStudioScope $scope): void
    {
        // 1.17: the filter also runs with scopes and no tenancy (the tenant columns are then not mapped).
        $filter->setParameter('tenancy', $scope->tenancy ? '1' : '0', Types::STRING);
        $filter->setParameter('scope_mode', self::scopeMode($scope), Types::STRING);
        $filter->setParameter('host_scope', $scope->hostScope instanceof WorkspaceScope ? $scope->hostScope->value : '', Types::STRING);
        // An empty list would be invalid SQL: '' is the tenant level, already allowed in this mode.
        $filter->setParameterList('scopes', $scope->visibleScopes === null || $scope->visibleScopes === [] ? [''] : $scope->visibleScopes, Types::STRING);
        $filter->setParameter('tenant_type', $scope->tenant->type, Types::STRING);
        $filter->setParameter('tenant_id', $scope->tenant->id, Types::STRING);
        $filter->setParameter('restrict_ids', $scope->visibleIds === null ? '0' : '1', Types::STRING);
        // An empty list would be invalid SQL: id 0 never exists.
        $filter->setParameterList('ids', $scope->visibleIds === null || $scope->visibleIds === [] ? [0] : $scope->visibleIds, Types::INTEGER);
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $class = $targetEntity->getName();
        $base  = self::TABLES[$class] ?? null;
        if ($base === null || !str_ends_with($targetEntity->getTableName(), $base)) {
            return '';
        }
        $prefix = substr($targetEntity->getTableName(), 0, strlen($targetEntity->getTableName()) - strlen($base));

        $workspaces = sprintf(
            'SELECT nast_w.id FROM %sworkspace nast_w WHERE %s',
            $prefix,
            $this->workspaceCondition('nast_w'),
        );
        $endpoints = sprintf(
            'SELECT nast_e.id FROM %sendpoint nast_e INNER JOIN %sservice nast_s ON nast_s.id = nast_e.service_id WHERE nast_s.workspace_id IN (%s)',
            $prefix,
            $prefix,
            $workspaces,
        );

        return match ($class) {
            ApiWorkspace::class                      => $this->workspaceCondition($targetTableAlias),
            ApiService::class, ApiEnvironment::class => sprintf('%s.workspace_id IN (%s)', $targetTableAlias, $workspaces),
            ApiEndpoint::class, ApiFolder::class     => sprintf('%s.service_id IN (SELECT nast_s2.id FROM %sservice nast_s2 WHERE nast_s2.workspace_id IN (%s))', $targetTableAlias, $prefix, $workspaces),
            ApiEnvironmentVariable::class            => sprintf('%s.environment_id IN (SELECT nast_v.id FROM %senvironment nast_v WHERE nast_v.workspace_id IN (%s))', $targetTableAlias, $prefix, $workspaces),
            default                                  => sprintf('%s.endpoint_id IN (%s)', $targetTableAlias, $endpoints),
        };
    }

    private static function scopeMode(ApiStudioScope $scope): string
    {
        if (!$scope->scopes) {
            return self::SCOPE_OFF;
        }

        if ($scope->hostScope instanceof WorkspaceScope) {
            return self::SCOPE_EXACT;
        }

        if (!$scope->tenantListAll || $scope->visibleScopes === []) {
            return self::SCOPE_UNSCOPED;
        }

        return $scope->visibleScopes === null ? self::SCOPE_ALL : self::SCOPE_VISIBLE;
    }

    private function workspaceCondition(string $alias): string
    {
        $conditions = [];
        if ($this->getParameter('tenancy') === "'1'") {
            $conditions[] = sprintf('%1$s.tenant_type = %2$s AND %1$s.tenant_id = %3$s', $alias, $this->getParameter('tenant_type'), $this->getParameter('tenant_id'));
        }

        $scope = match ($this->getParameter('scope_mode')) {
            "'" . self::SCOPE_EXACT . "'"    => sprintf('%s.host_scope = %s', $alias, $this->getParameter('host_scope')),
            "'" . self::SCOPE_UNSCOPED . "'" => sprintf("%s.host_scope = ''", $alias),
            "'" . self::SCOPE_VISIBLE . "'"  => sprintf("(%1\$s.host_scope = '' OR %1\$s.host_scope IN (%2\$s))", $alias, $this->getParameterList('scopes')),
            default                          => null,
        };
        if ($scope !== null) {
            $conditions[] = $scope;
        }

        if ($this->getParameter('restrict_ids') === "'1'") {
            $conditions[] = sprintf('%s.id IN (%s)', $alias, $this->getParameterList('ids'));
        }

        return '(' . ($conditions === [] ? '1 = 1' : implode(' AND ', $conditions)) . ')';
    }
}
