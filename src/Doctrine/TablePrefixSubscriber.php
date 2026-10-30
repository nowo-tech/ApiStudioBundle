<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

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
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $this->applyPrefix($metadata);
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
