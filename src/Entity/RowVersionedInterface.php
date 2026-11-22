<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Entity;

use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Doctrine\RowVersionListener;

/**
 * An Api Studio row whose edit form is guarded by a row version (1.20, REQ-RUNTIME-003 rule 4): the workspace, service,
 * endpoint and environment. The `row_version` column grows by one on every write of the row (and of the rows its edit
 * form also writes: endpoint translations, environment variables); an edit saves only on the version its form was
 * rendered from ({@see RowVersionGuard}).
 */
interface RowVersionedInterface
{
    public function getId(): ?int;

    /** The version of the row when it was loaded (1 for a row written before 1.20). */
    public function getRowVersion(): int;

    /**
     * Records the version the database now holds. Internal: only the bundle's {@see RowVersionGuard}
     * and {@see RowVersionListener} call it, after their own atomic UPDATE.
     *
     * @internal
     */
    public function markRowVersion(int $version): void;
}
