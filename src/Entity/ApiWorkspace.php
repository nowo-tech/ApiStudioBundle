<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;

/**
 * Top-level workspace grouping services, environments, and endpoints.
 */
#[ORM\Entity(repositoryClass: ApiWorkspaceRepository::class)]
#[ORM\Table(name: 'workspace')]
#[ORM\UniqueConstraint(name: 'uniq_workspace_slug', columns: ['slug'])]
class ApiWorkspace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** @var Collection<int, ApiService> */
    #[ORM\OneToMany(
        targetEntity: ApiService::class,
        mappedBy: 'workspace',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $services;

    /** @var Collection<int, ApiEnvironment> */
    #[ORM\OneToMany(
        targetEntity: ApiEnvironment::class,
        mappedBy: 'workspace',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $environments;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->name         = $name;
        $this->slug         = $slug;
        $this->services     = new ArrayCollection();
        $this->environments = new ArrayCollection();
        $this->createdAt    = new DateTimeImmutable();
        $this->updatedAt    = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this->touch();
    }

    /** @return Collection<int, ApiService> */
    public function getServices(): Collection
    {
        return $this->services;
    }

    public function addService(ApiService $service): self
    {
        if (!$this->services->contains($service)) {
            $this->services->add($service);
            $service->setWorkspace($this);
        }

        return $this->touch();
    }

    public function removeService(ApiService $service): self
    {
        if ($this->services->removeElement($service) && $service->getWorkspace() === $this) {
            $service->setWorkspace(null);
        }

        return $this->touch();
    }

    /** @return Collection<int, ApiEnvironment> */
    public function getEnvironments(): Collection
    {
        return $this->environments;
    }

    public function addEnvironment(ApiEnvironment $environment): self
    {
        if (!$this->environments->contains($environment)) {
            $this->environments->add($environment);
            $environment->setWorkspace($this);
        }

        return $this->touch();
    }

    public function removeEnvironment(ApiEnvironment $environment): self
    {
        if ($this->environments->removeElement($environment) && $environment->getWorkspace() === $this) {
            $environment->setWorkspace(null);
        }

        return $this->touch();
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
