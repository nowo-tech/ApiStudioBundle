<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;

use function sprintf;

/**
 * Metadata driver that maps nothing: registered for the Api Studio entity namespace on every entity manager other
 * than `nowo_api_studio.entity_manager` by {@see ApiStudioEntityManagerPass}.
 *
 * A {@see MappingDriverChain} asks its namespace drivers before its default driver, so this driver makes the Api
 * Studio entities transient on those managers even when their chain has a default driver that claims every class
 * carrying `#[ORM\Entity]` (DoctrineBundle 3.4 `auto_discover_entities`, enabled by default on a manager without
 * `mappings` and `auto_mapping`). `ManagerRegistry::getManagerForClass()` then answers the configured manager on
 * DoctrineBundle 2.x and 3.x alike. Stateless (worker-safe).
 */
final class ExcludedEntityNamespaceDriver implements MappingDriver
{
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        throw new MappingException(sprintf('Class "%s" is not mapped on this entity manager (nowo_api_studio.entity_manager maps it on another one).', $className));
    }

    /**
     * @return list<class-string>
     */
    public function getAllClassNames(): array
    {
        return [];
    }

    public function isTransient(string $className): bool
    {
        return true;
    }
}
