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

    /**
     * Not mapped: the stored value is an encrypted payload that no configured key opens (another key, corrupt row).
     * The payload stays in {@see self::$value} untouched (so a flush never rewrites it), but it is never handed out.
     */
    private bool $unreadable = false;

    /** Not mapped: a request to stop treating an unreadable value as secret was refused (form error). */
    private bool $secretChangeRefused = false;

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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->environment = $environment;

        return $this;
    }

    public function getVariableKey(): string
    {
        return $this->variableKey;
    }

    public function setVariableKey(string $variableKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->variableKey = VariableSyntax::normalizeKey($variableKey);

        return $this;
    }

    /**
     * The plaintext value; '' for an unreadable secret ({@see self::isUnreadable()}): its payload is never handed out.
     */
    public function getValue(): string
    {
        return $this->unreadable ? '' : $this->value;
    }

    /**
     * An empty value keeps an unreadable secret as stored (the write-only secret field submits '' for "unchanged");
     * any other value replaces it and the variable is readable again.
     */
    public function setValue(string $value): self
    {
        if ($this->unreadable && $value === '') {
            return $this;
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value      = $value;
        $this->unreadable = false;

        return $this;
    }

    /**
     * Marks the loaded value as an encrypted payload that cannot be decrypted (fail closed: it is never re-saved as
     * ciphertext, never sent and never exported; the variable needs re-entry).
     *
     * @internal called by the secret encryption listener after load
     */
    public function markUnreadable(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->unreadable = true;
    }

    /**
     * True when the stored secret cannot be decrypted with the configured key: it needs re-entry.
     */
    public function isUnreadable(): bool
    {
        return $this->unreadable;
    }

    /**
     * The raw stored value (encrypted payload for secrets).
     *
     * @internal for the encryption listener only
     */
    public function getStoredValue(): string
    {
        return $this->value;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    /**
     * An unreadable secret stays secret: unchecking it would turn its encrypted payload into a visible value. The
     * refusal is remembered for the form ({@see self::isSecretChangeRefused()}); re-enter the value first.
     */
    public function setSecret(bool $secret): self
    {
        if ($this->unreadable && !$secret) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->secretChangeRefused = true;

            return $this;
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secret = $secret;

        return $this;
    }

    public function isSecretChangeRefused(): bool
    {
        return $this->secretChangeRefused;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;

        return $this;
    }
}
