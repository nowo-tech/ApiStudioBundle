<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The `row_version` column of {@see RowVersionedInterface} (1.20): `INTEGER NOT NULL DEFAULT 1`, additive (rows written
 * before 1.20 start at 1). Not a Doctrine `#[ORM\Version]`: the bundle moves it with atomic
 * `row_version = row_version + 1` statements, so writers that do not carry a version (seed API, documentation, folder
 * moves) never fail on it.
 */
trait RowVersionTrait
{
    #[ORM\Column(name: 'row_version', type: Types::INTEGER, options: ['default' => 1])]
    private int $rowVersion = 1;

    public function getRowVersion(): int
    {
        return $this->rowVersion;
    }

    /** @internal */
    public function markRowVersion(int $version): void
    {
        $this->rowVersion = $version; // @igor-ignore - a Doctrine entity field (one row per request), never a shared service
    }
}
