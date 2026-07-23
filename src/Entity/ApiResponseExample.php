<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiResponseExampleRepository;

/**
 * Sample response for documentation.
 */
#[ORM\Entity(repositoryClass: ApiResponseExampleRepository::class)]
#[ORM\Table(name: 'response_example')]
class ApiResponseExample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiEndpoint::class, inversedBy: 'responseExamples')]
    #[ORM\JoinColumn(name: 'endpoint_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiEndpoint $endpoint = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private string $name;

    #[ORM\Column(name: 'status_code', type: Types::INTEGER)]
    private int $statusCode = 200;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    #[ORM\Column(name: 'response_body', type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(string $name, int $statusCode = 200)
    {
        $this->name       = $name;
        $this->statusCode = $statusCode;
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

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;

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

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): self
    {
        $this->responseBody = $responseBody;

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
