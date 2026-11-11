<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;

use function array_keys;
use function sprintf;

/**
 * The entity manager of the Api Studio entities (1.12, architecture bar rule 6): `nowo_api_studio.entity_manager` when
 * set, else the manager that maps {@see ApiWorkspace} (the default one in a standard host). Never the host default by
 * accident. Stateless (worker-safe): the manager is looked up per call through the registry, so a manager the
 * registry reset is picked up on the next call.
 *
 * @internal
 */
final readonly class ApiStudioEntityManagerResolver
{
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $managerName = null,
    ) {
    }

    public function entityManager(): EntityManagerInterface
    {
        $manager = $this->find();
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager maps %s (nowo_api_studio.entity_manager: %s).', ApiWorkspace::class, $this->managerName ?? 'null'));
        }

        return $manager;
    }

    /**
     * The bundle's manager, null when no ORM manager maps the entities.
     */
    public function find(): ?EntityManagerInterface
    {
        $manager = $this->managerName !== null
            ? $this->registry->getManager($this->managerName)
            : $this->registry->getManagerForClass(ApiWorkspace::class);

        return $manager instanceof EntityManagerInterface ? $manager : null;
    }

    /**
     * Name of the bundle's manager in the registry (null when the registry does not know it).
     */
    public function managerName(): ?string
    {
        if ($this->managerName !== null) {
            return $this->managerName;
        }

        $manager = $this->find();
        if (!$manager instanceof EntityManagerInterface) {
            return null;
        }

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) === $manager) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resets the bundle's manager when a failed flush closed it; never touches any other manager.
     */
    public function resetIfClosed(): void
    {
        $manager = $this->find();
        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            return;
        }

        $name = $this->managerName();
        if ($name !== null) {
            $this->registry->resetManager($name);
        }
    }
}
