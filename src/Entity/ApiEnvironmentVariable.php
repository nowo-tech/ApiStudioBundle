<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentVariableRepository;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

/**
 * Key/value environment variable for request resolution.
 */
#[ORM\Entity(repositoryClass: ApiEnvironmentVariableRepository::class)]
#[ORM\Table(name: 'environment_variable')]
#[ORM\UniqueConstraint(name: 'uniq_env_var_key', columns: ['environment_id', 'variable_key'])]
class ApiEnvironmentVariable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiEnvironment::class, inversedBy: 'variables')]
    #[ORM\JoinColumn(name: 'environment_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiEnvironment $environment = null;

    #[ORM\Column(name: 'variable_key', type: Types::STRING, length: 128)]
    private string $variableKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $value = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $secret = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct(string $variableKey, string $value = '')
    {
        $this->variableKey = VariableSyntax::normalizeKey($variableKey);
        $this->value       = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getVariableKey(): string
    {
        return $this->variableKey;
    }

    public function setVariableKey(string $variableKey): self
    {
        $this->variableKey = VariableSyntax::normalizeKey($variableKey);

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setSecret(bool $secret): self
    {
        $this->secret = $secret;

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
}
