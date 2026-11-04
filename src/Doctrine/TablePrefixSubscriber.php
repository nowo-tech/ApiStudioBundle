<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function is_array;

/**
 * Applies the configured table prefix to all Api Studio entity tables and indexes.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TablePrefixSubscriber
{
    private const ENTITY_NAMESPACE = 'Nowo\\ApiStudioBundle\\Entity\\';

    public function __construct(
        private readonly string $tablePrefix,
        private readonly bool $tenancy = false,
        private readonly bool $historyActor = false,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        if ($this->tenancy && $metadata->getName() === ApiWorkspace::class) {
            $this->mapTenant($metadata);
        }
        if ($this->historyActor && $metadata->getName() === ApiRequestHistory::class) {
            $this->mapHistoryActor($metadata);
        }

        $this->applyPrefix($metadata);
    }

    /**
     * `nowo_api_studio.tenancy.enabled`: the workspace's tenant columns, and the slug unique per tenant instead of per
     * instance. Without it the schema is exactly 1.10's.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapTenant(ClassMetadata $metadata): void
    {
        if (!$metadata->hasField('tenantType')) {
            $metadata->mapField(['fieldName' => 'tenantType', 'columnName' => 'tenant_type', 'type' => Types::STRING, 'length' => 32, 'options' => ['default' => '']]);
            $metadata->mapField(['fieldName' => 'tenantId', 'columnName' => 'tenant_id', 'type' => Types::STRING, 'length' => 64, 'options' => ['default' => '']]);
        }

        /** @var array<string, mixed> $table */
        $table   = $metadata->table;
        $uniques = is_array($table['uniqueConstraints'] ?? null) ? $table['uniqueConstraints'] : [];
        unset($uniques['uniq_workspace_slug']);
        $uniques['uniq_workspace_tenant_slug'] = ['columns' => ['tenant_type', 'tenant_id', 'slug']];
        $indexes                               = is_array($table['indexes'] ?? null) ? $table['indexes'] : [];
        $indexes['idx_workspace_tenant']       = ['columns' => ['tenant_type', 'tenant_id']];
        $table['uniqueConstraints']            = $uniques;
        $table['indexes']                      = $indexes;
        $metadata->setPrimaryTable($table);
    }

    /**
     * `nowo_api_studio.history.scope: user`: who executed each request (opaque actor id), and an index for the
     * per-user list of an endpoint.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapHistoryActor(ClassMetadata $metadata): void
    {
        if (!$metadata->hasField('executedBy')) {
            $metadata->mapField(['fieldName' => 'executedBy', 'columnName' => 'executed_by', 'type' => Types::STRING, 'length' => 64, 'nullable' => true]);
        }

        /** @var array<string, mixed> $table */
        $table                                 = $metadata->table;
        $indexes                               = is_array($table['indexes'] ?? null) ? $table['indexes'] : [];
        $indexes['idx_history_endpoint_actor'] = ['columns' => ['endpoint_id', 'executed_by', 'executed_at']];
        $table['indexes']                      = $indexes;
        $metadata->setPrimaryTable($table);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function applyPrefix(ClassMetadata $metadata): void
    {
        // Runtime shape from Doctrine may be wider than ClassMetadata PHPDoc stubs.
        /** @var array<string, mixed> $table */
        $table         = $metadata->table;
        $table['name'] = $this->tablePrefix . $table['name'];

        if (isset($table['uniqueConstraints']) && is_array($table['uniqueConstraints'])) {
            $table['uniqueConstraints'] = $this->prefixNamedDefinitions($table['uniqueConstraints']);
        }

        if (isset($table['indexes']) && is_array($table['indexes'])) {
            $table['indexes'] = $this->prefixNamedDefinitions($table['indexes']);
        }

        $metadata->setPrimaryTable($table);

        // ORM 3 association mappings are objects: rename the join table in place (writing the array shape back
        // through ArrayAccess fails, JoinTableMapping::fromMappingArray() expects an array).
        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping instanceof ManyToManyOwningSideMapping) {
                $mapping->joinTable->name = $this->tablePrefix . $mapping->joinTable->name;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function prefixNamedDefinitions(array $definitions): array
    {
        $prefixed = [];
        foreach ($definitions as $name => $definition) {
            $prefixed[$this->tablePrefix . $name] = $definition;
        }

        return $prefixed;
    }
}
