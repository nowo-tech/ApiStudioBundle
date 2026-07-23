<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

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

        foreach ($metadata->associationMappings as $fieldName => $mapping) {
            if (!isset($mapping['joinTable']['name'])) {
                continue;
            }

            $joinTable                                              = $mapping['joinTable'];
            $joinTable['name']                                      = $this->tablePrefix . $joinTable['name'];
            $metadata->associationMappings[$fieldName]['joinTable'] = $joinTable;
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
