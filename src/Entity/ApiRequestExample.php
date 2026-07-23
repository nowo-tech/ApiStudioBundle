<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiRequestExampleRepository;

/**
 * Sample request payload for documentation and quick-fill in the tester.
 */
#[ORM\Entity(repositoryClass: ApiRequestExampleRepository::class)]
#[ORM\Table(name: 'request_example')]
class ApiRequestExample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiEndpoint::class, inversedBy: 'requestExamples')]
    #[ORM\JoinColumn(name: 'endpoint_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiEndpoint $endpoint = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /** @var array<string, string> */
    #[ORM\Column(name: 'query_params', type: Types::JSON)]
    private array $queryParams = [];

    #[ORM\Column(name: 'request_body', type: Types::TEXT, nullable: true)]
    private ?string $requestBody = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpoint(): ?ApiEndpoint
    {
        return $this->endpoint;
    }

    public function setEndpoint(?ApiEndpoint $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
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

        return $this;
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

        return $this;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function setRequestBody(?string $requestBody): self
    {
        $this->requestBody = $requestBody;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
