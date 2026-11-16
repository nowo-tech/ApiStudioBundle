<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function is_array;
use function is_string;

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
        private readonly bool $folders = false,
        private readonly bool $scriptLevels = false,
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
        if ($metadata->getName() === ApiFolder::class) {
            if (!$this->folders) {
                // Folders off: no table, no association (the schema stays exactly 1.13's).
                $metadata->isMappedSuperclass  = true;
                $metadata->associationMappings = [];

                return;
            }
            $this->mapFolderCreator($metadata, $event->getObjectManager()->getConnection());
            if ($this->scriptLevels) {
                $this->mapScripts($metadata);
            }
        }
        if ($this->scriptLevels && $metadata->getName() === ApiWorkspace::class) {
            $this->mapScripts($metadata);
        }
        if ($this->folders && $metadata->getName() === ApiEndpoint::class) {
            $this->mapEndpointFolder($metadata);
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
     * `scripts.mode: sandbox` (1.15): workspace and folder scripts (`pre_request_script`, `post_request_script`).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapScripts(ClassMetadata $metadata): void
    {
        foreach (['preRequestScript' => 'pre_request_script', 'postRequestScript' => 'post_request_script'] as $field => $column) {
            if (!$metadata->hasField($field)) {
                $metadata->mapField(['fieldName' => $field, 'columnName' => $column, 'type' => Types::TEXT, 'nullable' => true]);
            }
        }
    }

    /**
     * `nowo_api_studio.folders.enabled` (1.14): the endpoint's folder (`folder_id`, SET NULL when the folder goes).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapEndpointFolder(ClassMetadata $metadata): void
    {
        if (!$metadata->hasAssociation('folder')) {
            $metadata->mapManyToOne([
                'fieldName'    => 'folder',
                'targetEntity' => ApiFolder::class,
                'joinColumns'  => [['name' => 'folder_id', 'referencedColumnName' => 'id', 'nullable' => true, 'onDelete' => 'SET NULL']],
            ]);
        }

        /** @var array<string, mixed> $table */
        $table                          = $metadata->table;
        $indexes                        = is_array($table['indexes'] ?? null) ? $table['indexes'] : [];
        $indexes['idx_endpoint_folder'] = ['columns' => ['folder_id']];
        $table['indexes']               = $indexes;
        $metadata->setPrimaryTable($table);
    }

    /**
     * Identifier columns compare exactly (audit LQ): `created_by` gets a binary NO PAD collation on MySQL
     * (`utf8mb4_0900_bin`) and MariaDB (`utf8mb4_nopad_bin`). PostgreSQL and SQLite compare exactly already.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapFolderCreator(ClassMetadata $metadata, Connection $connection): void
    {
        $collation = self::binaryCollation($connection);
        if ($collation === null || !$metadata->hasField('createdBy')) {
            return;
        }

        $mapping              = $metadata->getFieldMapping('createdBy');
        $options              = is_array($mapping->options) ? $mapping->options : [];
        $options['collation'] = $collation;
        $mapping->options     = $options;
    }

    /**
     * Binary NO PAD collation of the connection's platform, or null when it is not MySQL / MariaDB. The platform is
     * read from the configured server version (`serverVersion`, required for the collation), so building metadata
     * never needs a database connection.
     */
    public static function binaryCollation(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $version = $params['serverVersion'] ?? $params['primary']['serverVersion'] ?? null;
        if (is_string($version) && $version !== '') {
            // With a server version DBAL builds the platform without connecting.
            $platform = $connection->getDatabasePlatform();
            if ($platform instanceof MariaDBPlatform) {
                return 'utf8mb4_nopad_bin';
            }

            return $platform instanceof AbstractMySQLPlatform ? 'utf8mb4_0900_bin' : null;
        }

        // RJ-L7: without a server version MySQL and MariaDB cannot be told apart without connecting, and each refuses
        // the other's collation name: emit none (the PHP side compares creator ids with === anyway).
        return null;
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
