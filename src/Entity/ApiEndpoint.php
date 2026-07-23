<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;

/**
 * Documented and testable API endpoint.
 */
#[ORM\Entity(repositoryClass: ApiEndpointRepository::class)]
#[ORM\Table(name: 'endpoint')]
#[ORM\UniqueConstraint(name: 'uniq_endpoint_service_slug', columns: ['service_id', 'slug'])]
class ApiEndpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiService::class, inversedBy: 'endpoints')]
    #[ORM\JoinColumn(name: 'service_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiService $service = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: HttpMethod::class)]
    private HttpMethod $method = HttpMethod::Get;

    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $path = '/';

    #[ORM\Column(name: 'soap_action', type: Types::STRING, length: 255, nullable: true)]
    private ?string $soapAction = null;

    #[ORM\Column(name: 'content_type', type: Types::STRING, length: 128)]
    private string $contentType = 'application/json';

    #[ORM\Column(name: 'request_body_template', type: Types::TEXT, nullable: true)]
    private ?string $requestBodyTemplate = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /** @var array<string, string> */
    #[ORM\Column(name: 'query_params', type: Types::JSON)]
    private array $queryParams = [];

    #[ORM\Column(name: 'pre_request_script', type: Types::TEXT, nullable: true)]
    private ?string $preRequestScript = null;

    #[ORM\Column(name: 'post_request_script', type: Types::TEXT, nullable: true)]
    private ?string $postRequestScript = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    /** @var Collection<int, ApiEndpointTranslation> */
    #[ORM\OneToMany(
        targetEntity: ApiEndpointTranslation::class,
        mappedBy: 'endpoint',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $translations;

    /** @var Collection<int, ApiRequestExample> */
    #[ORM\OneToMany(
        targetEntity: ApiRequestExample::class,
        mappedBy: 'endpoint',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $requestExamples;

    /** @var Collection<int, ApiResponseExample> */
    #[ORM\OneToMany(
        targetEntity: ApiResponseExample::class,
        mappedBy: 'endpoint',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $responseExamples;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug)
    {
        $this->name             = $name;
        $this->slug             = $slug;
        $this->translations     = new ArrayCollection();
        $this->requestExamples  = new ArrayCollection();
        $this->responseExamples = new ArrayCollection();
        $this->createdAt        = new DateTimeImmutable();
        $this->updatedAt        = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getService(): ?ApiService
    {
        return $this->service;
    }

    public function setService(?ApiService $service): self
    {
        $this->service = $service;

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

    public function getMethod(): HttpMethod
    {
        return $this->method;
    }

    public function setMethod(HttpMethod $method): self
    {
        $this->method = $method;

        return $this->touch();
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this->touch();
    }

    public function getSoapAction(): ?string
    {
        return $this->soapAction;
    }

    public function setSoapAction(?string $soapAction): self
    {
        $this->soapAction = $soapAction;

        return $this->touch();
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): self
    {
        $this->contentType = $contentType;

        return $this->touch();
    }

    public function getRequestBodyTemplate(): ?string
    {
        return $this->requestBodyTemplate;
    }

    public function setRequestBodyTemplate(?string $requestBodyTemplate): self
    {
        $this->requestBodyTemplate = $requestBodyTemplate;

        return $this->touch();
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @param array<string, string> $headers */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this->touch();
    }

    /** @return array<string, string> */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /** @param array<string, string> $queryParams */
    public function setQueryParams(array $queryParams): self
    {
        $this->queryParams = $queryParams;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

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

    /** @return Collection<int, ApiEndpointTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ApiEndpointTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setEndpoint($this);
        }

        return $this->touch();
    }

    public function removeTranslation(ApiEndpointTranslation $translation): self
    {
        if ($this->translations->removeElement($translation) && $translation->getEndpoint() === $this) {
            $translation->setEndpoint(null);
        }

        return $this->touch();
    }

    public function getTranslation(string $locale): ?ApiEndpointTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    /** @return Collection<int, ApiRequestExample> */
    public function getRequestExamples(): Collection
    {
        return $this->requestExamples;
    }

    public function addRequestExample(ApiRequestExample $example): self
    {
        if (!$this->requestExamples->contains($example)) {
            $this->requestExamples->add($example);
            $example->setEndpoint($this);
        }

        return $this->touch();
    }

    public function removeRequestExample(ApiRequestExample $example): self
    {
        if ($this->requestExamples->removeElement($example) && $example->getEndpoint() === $this) {
            $example->setEndpoint(null);
        }

        return $this->touch();
    }

    /** @return Collection<int, ApiResponseExample> */
    public function getResponseExamples(): Collection
    {
        return $this->responseExamples;
    }

    public function addResponseExample(ApiResponseExample $example): self
    {
        if (!$this->responseExamples->contains($example)) {
            $this->responseExamples->add($example);
            $example->setEndpoint($this);
        }

        return $this->touch();
    }

    public function removeResponseExample(ApiResponseExample $example): self
    {
        if ($this->responseExamples->removeElement($example) && $example->getEndpoint() === $this) {
            $example->setEndpoint(null);
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
