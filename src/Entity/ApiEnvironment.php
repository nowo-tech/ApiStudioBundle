<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;

/**
 * Environment (dev, staging, prod) with variable sets for a workspace.
 */
#[ORM\Entity(repositoryClass: ApiEnvironmentRepository::class)]
#[ORM\Table(name: 'environment')]
#[ORM\UniqueConstraint(name: 'uniq_environment_workspace_slug', columns: ['workspace_id', 'slug'])]
class ApiEnvironment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiWorkspace::class, inversedBy: 'environments')]
    #[ORM\JoinColumn(name: 'workspace_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiWorkspace $workspace = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(name: 'is_default', type: Types::BOOLEAN)]
    private bool $isDefault = false;

    /** @var Collection<int, ApiEnvironmentVariable> */
    #[ORM\OneToMany(
        targetEntity: ApiEnvironmentVariable::class,
        mappedBy: 'environment',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['variableKey' => 'ASC'])]
    private Collection $variables;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->name      = $name;
        $this->slug      = $slug;
        $this->variables = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkspace(): ?ApiWorkspace
    {
        return $this->workspace;
    }

    public function setWorkspace(?ApiWorkspace $workspace): self
    {
        $this->workspace = $workspace;

        return $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this->touch();
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this->touch();
    }

    /** @return Collection<int, ApiEnvironmentVariable> */
    public function getVariables(): Collection
    {
        return $this->variables;
    }

    public function addVariable(ApiEnvironmentVariable $variable): self
    {
        if (!$this->variables->contains($variable)) {
            $this->variables->add($variable);
            $variable->setEnvironment($this);
        }

        return $this->touch();
    }

    public function removeVariable(ApiEnvironmentVariable $variable): self
    {
        if ($this->variables->removeElement($variable) && $variable->getEnvironment() === $this) {
            $variable->setEnvironment(null);
        }

        return $this->touch();
    }

    /** @return array<string, string> */
    public function getVariableMap(): array
    {
        $map = [];
        foreach ($this->variables as $variable) {
            $map[$variable->getVariableKey()] = $variable->getValue();
        }

        return $map;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
