<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Service\VariableSyntax;

/**
 * A script variable (1.15, `scripts.mode: sandbox`): `pm.globals` (workspace, no service) or `pm.collectionVariables`
 * (one service). Plain values only: these scopes have no secret flag and are never resolved server-side into
 * `pm.sendRequest`.
 *
 * Mapped only in sandbox mode ({@see TablePrefixSubscriber} turns the class into a mapped superclass otherwise). The
 * key column gets a binary NO PAD collation on MySQL / MariaDB (exact identifier comparison).
 */
#[ORM\Entity]
#[ORM\Table(name: 'script_variable')]
#[ORM\UniqueConstraint(name: 'uniq_script_var_key', columns: ['workspace_id', 'scope_id', 'variable_key'])]
class ApiScriptVariable
{
    /** Longest value stored (characters). */
    public const MAX_VALUE_LENGTH = 65535;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiWorkspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', nullable: false, onDelete: 'CASCADE')]
    private ApiWorkspace $workspace;

    /** Null for a global (workspace) variable. */
    #[ORM\ManyToOne(targetEntity: ApiService::class)]
    #[ORM\JoinColumn(name: 'service_id', nullable: true, onDelete: 'CASCADE')]
    private ?ApiService $service;

    /** 0 for a global, the service id for a collection variable (part of the unique key; a null would not be). */
    #[ORM\Column(name: 'scope_id', type: Types::INTEGER)]
    private int $scopeId;

    #[ORM\Column(name: 'variable_key', type: Types::STRING, length: 128)]
    private string $variableKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(ApiWorkspace $workspace, ?ApiService $service, string $variableKey, string $value)
    {
        $this->workspace   = $workspace;
        $this->service     = $service;
        $this->scopeId     = (int) $service?->getId();
        $this->variableKey = VariableSyntax::normalizeKey($variableKey);
        $this->value       = $value;
        $this->updatedAt   = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkspace(): ApiWorkspace
    {
        return $this->workspace;
    }

    public function getService(): ?ApiService
    {
        return $this->service;
    }

    /** 0 for a global, else the service id. */
    public function getScopeId(): int
    {
        return $this->scopeId;
    }

    public function getVariableKey(): string
    {
        return $this->variableKey;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = $value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
