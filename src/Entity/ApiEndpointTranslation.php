<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiEndpointTranslationRepository;

/**
 * Localized documentation for an endpoint.
 */
#[ORM\Entity(repositoryClass: ApiEndpointTranslationRepository::class)]
#[ORM\Table(name: 'endpoint_translation')]
#[ORM\UniqueConstraint(name: 'uniq_endpoint_locale', columns: ['endpoint_id', 'locale'])]
class ApiEndpointTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ApiEndpoint::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'endpoint_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiEndpoint $endpoint = null;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(string $locale)
    {
        $this->locale = $locale;
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }
}
