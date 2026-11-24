<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ApiStudioBundle\Doctrine\FolderParentDetachListener;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Service\FolderTree;
use Symfony\Component\Validator\Constraints as Assert;

use function count;

/**
 * Folder of a service's endpoint tree (1.14, `nowo_api_studio.folders.enabled`): a parent folder of the same service
 * (null = top level), an order among its siblings, and auth / headers that the endpoints below inherit.
 *
 * Mapped only when folders are enabled ({@see TablePrefixSubscriber} turns the class into
 * a mapped superclass otherwise, so the schema stays exactly 1.13's). Row-versioned since 1.23: the edit form saves
 * only on the version it was rendered from ({@see RowVersionedInterface}).
 */
#[ORM\Entity(repositoryClass: ApiFolderRepository::class)]
#[ORM\Table(name: 'folder')]
#[ORM\Index(name: 'idx_folder_service_parent', columns: ['service_id', 'parent_id', 'sort_order'])]
class ApiFolder implements RowVersionedInterface
{
    use RowVersionTrait;

    /** Longest creator id stored (opaque actor id, exact binary comparison). */
    public const MAX_CREATOR_LENGTH = 128;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    #[ORM\ManyToOne(targetEntity: ApiService::class)]
    #[ORM\JoinColumn(name: 'service_id', nullable: false, onDelete: 'CASCADE')]
    private ?ApiService $service = null;

    /**
     * No ON DELETE action (RESTRICT / NO ACTION, audit RJ-M2): a folder row is never removed with its subtree by the
     * database. Folder deletes move the content up first ({@see FolderTree}); service
     * and workspace deletes detach the parents first ({@see FolderParentDetachListener}).
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?ApiFolder $parent = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 10000)]
    private ?string $description = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    /** Null = inherit from the parent folder, then the service. {@see AuthType::None} = explicitly no auth. */
    #[ORM\Column(name: 'auth_type', type: Types::STRING, length: 32, nullable: true, enumType: AuthType::class)]
    private ?AuthType $authType = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'auth_config', type: Types::JSON)]
    private array $authConfig = [];

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    /** Opaque actor id of who created the folder (for host sharing rules); binary NO PAD collation on MySQL. */
    #[ORM\Column(name: 'created_by', type: Types::STRING, length: self::MAX_CREATOR_LENGTH, nullable: true)]
    private ?string $createdBy = null;

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

    public function __construct(string $name)
    {
        $this->name      = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->service = $service;

        return $this->touch();
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parent = $parent;

        return $this->touch();
    }

    /**
     * Folders from the top-level one down to this one (inclusive). Stops on a cycle (never stored: moves refuse
     * them) so a corrupt row cannot loop.
     *
     * @return list<self>
     */
    public function getPath(): array
    {
        $path = [];
        $seen = [];
        for ($folder = $this; $folder instanceof self; $folder = $folder->getParent()) {
            $key = spl_object_id($folder);
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;
            array_unshift($path, $folder);
        }

        return $path;
    }

    /** 1 for a top-level folder. */
    public function getDepth(): int
    {
        return count($this->getPath());
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->sortOrder = $sortOrder;

        return $this->touch();
    }

    public function getAuthType(): ?AuthType
    {
        return $this->authType;
    }

    public function setAuthType(?AuthType $authType): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->authConfig = $authConfig;

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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->headers = $headers;

        return $this->touch();
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): self
    {
        if ($createdBy !== null && ($createdBy === '' || mb_strlen($createdBy) > self::MAX_CREATOR_LENGTH)) {
            $createdBy = null;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->createdBy = $createdBy;

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
