<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiRequestHistoryRepository;

/**
 * Persisted log of a request executed from the API tester.
 */
#[ORM\Entity(repositoryClass: ApiRequestHistoryRepository::class)]
#[ORM\Table(name: 'request_history')]
class ApiRequestHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiEndpoint::class)]
    #[ORM\JoinColumn(name: 'endpoint_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiEndpoint $endpoint = null;

    #[ORM\ManyToOne(targetEntity: ApiEnvironment::class)]
    #[ORM\JoinColumn(name: 'environment_id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiEnvironment $environment = null;

    #[ORM\Column(name: 'executed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $executedAt;

    #[ORM\Column(name: 'request_url', type: Types::STRING, length: 2048)]
    private string $requestUrl;

    #[ORM\Column(name: 'request_method', type: Types::STRING, length: 16)]
    private string $requestMethod;

    /** @var array<string, string> */
    #[ORM\Column(name: 'request_headers', type: Types::JSON)]
    private array $requestHeaders = [];

    #[ORM\Column(name: 'request_body', type: Types::TEXT, nullable: true)]
    private ?string $requestBody = null;

    #[ORM\Column(name: 'response_status', type: Types::INTEGER, nullable: true)]
    private ?int $responseStatus = null;

    /** @var array<string, string> */
    #[ORM\Column(name: 'response_headers', type: Types::JSON)]
    private array $responseHeaders = [];

    #[ORM\Column(name: 'response_body', type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success = false;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->executedAt = new DateTimeImmutable();
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

    public function getEnvironment(): ?ApiEnvironment
    {
        return $this->environment;
    }

    public function setEnvironment(?ApiEnvironment $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    public function getExecutedAt(): DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function getRequestUrl(): string
    {
        return $this->requestUrl;
    }

    public function setRequestUrl(string $requestUrl): self
    {
        $this->requestUrl = $requestUrl;

        return $this;
    }

    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(string $requestMethod): self
    {
        $this->requestMethod = $requestMethod;

        return $this;
    }

    /** @return array<string, string> */
    public function getRequestHeaders(): array
    {
        return $this->requestHeaders;
    }

    /** @param array<string, string> $requestHeaders */
    public function setRequestHeaders(array $requestHeaders): self
    {
        $this->requestHeaders = $requestHeaders;

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

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function setResponseStatus(?int $responseStatus): self
    {
        $this->responseStatus = $responseStatus;

        return $this;
    }

    /** @return array<string, string> */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /** @param array<string, string> $responseHeaders */
    public function setResponseHeaders(array $responseHeaders): self
    {
        $this->responseHeaders = $responseHeaders;

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

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
