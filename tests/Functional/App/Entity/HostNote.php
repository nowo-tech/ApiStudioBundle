<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host application entity on the host's default entity manager (dedicated entity manager tests): pending changes of
 * the host must never be flushed by Api Studio.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine UnitOfWork)

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $text,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
