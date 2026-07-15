<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;

/**
 * Third-party or internal API service within a workspace.
 */
#[ORM\Entity(repositoryClass: ApiServiceRepository::class)]
#[ORM\Table(name: 'service')]
#[ORM\UniqueConstraint(name: 'uniq_service_workspace_slug', columns: ['workspace_id', 'slug'])]
class ApiService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiWorkspace::class, inversedBy: 'services')]
    #[ORM\JoinColumn(name: 'workspace_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiWorkspace $workspace = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_url', type: Types::STRING, length: 512)]
    private string $baseUrl = '{{base_url}}';

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ApiProtocol::class)]
    private ApiProtocol $protocol = ApiProtocol::Rest;

    #[ORM\Column(name: 'auth_type', type: Types::STRING, length: 32, enumType: AuthType::class)]
    private AuthType $authType = AuthType::None;

    #[ORM\Column(name: 'auth_config', type: Types::JSON)]
    private array $authConfig = [];

    /** @var array<string, string> */
    #[ORM\Column(name: 'default_headers', type: Types::JSON)]
    private array $defaultHeaders = [];

    #[ORM\Column(name: 'pre_request_script', type: Types::TEXT, nullable: true)]
    private ?string $preRequestScript = null;

    #[ORM\Column(name: 'post_request_script', type: Types::TEXT, nullable: true)]
    private ?string $postRequestScript = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** @var Collection<int, ApiEndpoint> */
    #[ORM\OneToMany(
        targetEntity: ApiEndpoint::class,
        mappedBy: 'service',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $endpoints;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->name      = $name;
        $this->slug      = $slug;
        $this->endpoints = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this->touch();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this->touch();
    }

    public function getProtocol(): ApiProtocol
    {
        return $this->protocol;
    }

    public function setProtocol(ApiProtocol $protocol): self
    {
        $this->protocol = $protocol;

        return $this->touch();
    }

    public function getAuthType(): AuthType
    {
        return $this->authType;
    }

    public function setAuthType(AuthType $authType): self
    {
        $this->authType = $authType;

        return $this->touch();
    }

    /** @return array<string, mixed> */
    public function getAuthConfig(): array
    {
        return $this->authConfig;
    }

    /** @param array<string, mixed> $authConfig */
    public function setAuthConfig(array $authConfig): self
    {
        $this->authConfig = $authConfig;

        return $this->touch();
    }

    /** @return array<string, string> */
    public function getDefaultHeaders(): array
    {
        return $this->defaultHeaders;
    }

    /** @param array<string, string> $defaultHeaders */
    public function setDefaultHeaders(array $defaultHeaders): self
    {
        $this->defaultHeaders = $defaultHeaders;

        return $this->touch();
    }

    public function getPreRequestScript(): ?string
    {
        return $this->preRequestScript;
    }

    public function setPreRequestScript(?string $preRequestScript): self
    {
        $this->preRequestScript = $preRequestScript;

        return $this->touch();
    }

    public function getPostRequestScript(): ?string
    {
        return $this->postRequestScript;
    }

    public function setPostRequestScript(?string $postRequestScript): self
    {
        $this->postRequestScript = $postRequestScript;

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

    /** @return Collection<int, ApiEndpoint> */
    public function getEndpoints(): Collection
    {
        return $this->endpoints;
    }

    public function addEndpoint(ApiEndpoint $endpoint): self
    {
        if (!$this->endpoints->contains($endpoint)) {
            $this->endpoints->add($endpoint);
            $endpoint->setService($this);
        }

        return $this->touch();
    }

    public function removeEndpoint(ApiEndpoint $endpoint): self
    {
        if ($this->endpoints->removeElement($endpoint) && $endpoint->getService() === $this) {
            $endpoint->setService(null);
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
