<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Scope\WorkspaceScopeMover;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Validator\SlugFormat;
use Nowo\ApiStudioBundle\Validator\UniqueSlug;
use SortDirection;

/**
 * Top-level workspace grouping services, environments, and endpoints.
 */
#[ORM\Entity(repositoryClass: ApiWorkspaceRepository::class)]
#[ORM\Table(name: 'workspace')]
#[ORM\UniqueConstraint(name: 'uniq_workspace_slug', columns: ['slug'])]
#[UniqueSlug]
#[SlugFormat]
class ApiWorkspace implements RowVersionedInterface
{
    use RowVersionTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

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
    #[ORM\OrderBy(['name' => SortDirection::Ascending])]
    private Collection $services;

    /** @var Collection<int, ApiEnvironment> */
    #[ORM\OneToMany(
        targetEntity: ApiEnvironment::class,
        mappedBy: 'workspace',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['name' => SortDirection::Ascending])]
    private Collection $environments;

    /**
     * Tenant type, mapped (`tenant_type`) only with `nowo_api_studio.tenancy.enabled`
     * ({@see TablePrefixSubscriber}). '' = instance tenant.
     */
    private string $tenantType = '';

    /** Tenant id, mapped (`tenant_id`) only with tenancy on. */
    private string $tenantId = '';

    /**
     * Host scope (1.17), mapped (`host_scope`) only with `nowo_api_studio.scope.enabled`
     * ({@see TablePrefixSubscriber}). '' = tenant level.
     */
    private string $hostScope = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * Scripts of this level (1.15): not mapped by attributes; {@see TablePrefixSubscriber}
     * maps `pre_request_script` / `post_request_script` only with `scripts.mode: sandbox`.
     */
    private ?string $preRequestScript = null;

    private ?string $postRequestScript = null;

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

    public function getTenant(): ApiStudioTenant
    {
        return new ApiStudioTenant($this->tenantType, $this->tenantId);
    }

    /**
     * Sets the tenant of a new workspace. Moving a saved workspace to another tenant is not a UI operation.
     *
     * @throws LogicException once the workspace has an id
     */
    public function assignTenant(ApiStudioTenant $tenant): self
    {
        if ($this->id !== null && !$tenant->equals($this->getTenant())) {
            throw new LogicException('A saved Api Studio workspace cannot change tenant.');
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tenantType = $tenant->type;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tenantId = $tenant->id;

        return $this;
    }

    /**
     * 1.17: the host scope (e.g. a project), null at tenant level.
     */
    public function getScope(): ?WorkspaceScope
    {
        return WorkspaceScope::fromStored($this->hostScope);
    }

    /**
     * 1.17: stored form of the scope (`''` = tenant level), for exact comparisons.
     */
    public function getScopeKey(): string
    {
        return $this->hostScope;
    }

    /**
     * 1.17: sets the scope. Unlike the tenant, the host may move a saved workspace to another scope
     * ({@see WorkspaceScopeMover}); the studio UI never does.
     */
    public function assignScope(?WorkspaceScope $scope): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->hostScope = WorkspaceScope::key($scope);

        return $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this->touch();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->slug = $slug;

        return $this->touch();
    }

    /** Pre-request script of this level (1.15, `scripts.mode: sandbox`; unmapped otherwise). */
    public function getPreRequestScript(): ?string
    {
        return $this->preRequestScript;
    }

    public function setPreRequestScript(?string $preRequestScript): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->preRequestScript = $preRequestScript !== null && trim($preRequestScript) !== '' ? $preRequestScript : null;

        return $this->touch();
    }

    /** Post-response script of this level (1.15, `scripts.mode: sandbox`; unmapped otherwise). */
    public function getPostRequestScript(): ?string
    {
        return $this->postRequestScript;
    }

    public function setPostRequestScript(?string $postRequestScript): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->postRequestScript = $postRequestScript !== null && trim($postRequestScript) !== '' ? $postRequestScript : null;

        return $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;

        return $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
