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
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;

use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Defence in depth for tenancy (`nowo_api_studio_tenant`): during an Api Studio request every bundle query on a
 * workspace, or on a row that hangs off one (service, environment, endpoint, variable, examples, translations,
 * history), is narrowed to the request's tenant and visible workspaces. The explicit checks of the controllers stay
 * the control: SQL filters do not apply to entities already in the identity map, DBAL queries or DQL UPDATE / DELETE.
 * Enabled by {@see TenantSqlFilterListener}; off everywhere else.
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
    ];

    public static function configure(self $filter, ApiStudioScope $scope): void
    {
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
            ApiEndpoint::class                       => sprintf('%s.service_id IN (SELECT nast_s2.id FROM %sservice nast_s2 WHERE nast_s2.workspace_id IN (%s))', $targetTableAlias, $prefix, $workspaces),
            ApiEnvironmentVariable::class            => sprintf('%s.environment_id IN (SELECT nast_v.id FROM %senvironment nast_v WHERE nast_v.workspace_id IN (%s))', $targetTableAlias, $prefix, $workspaces),
            default                                  => sprintf('%s.endpoint_id IN (%s)', $targetTableAlias, $endpoints),
        };
    }

    private function workspaceCondition(string $alias): string
    {
        $condition = sprintf('%1$s.tenant_type = %2$s AND %1$s.tenant_id = %3$s', $alias, $this->getParameter('tenant_type'), $this->getParameter('tenant_id'));
        if ($this->getParameter('restrict_ids') === "'1'") {
            $condition .= sprintf(' AND %s.id IN (%s)', $alias, $this->getParameterList('ids'));
        }

        return '(' . $condition . ')';
    }
}
