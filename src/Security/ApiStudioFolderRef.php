<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * The folder a {@see ApiStudioFolderPermissionCheckerInterface} decides on (1.14): ids only, no bundle entity, so
 * DDD hosts can store grants against them. Later minors may append fields.
 */
final readonly class ApiStudioFolderRef
{
    /**
     * @param list<int> $ancestorIds the folders above this one, top-level first (empty for a top-level folder)
     * @param string|null $createdBy opaque actor id of the creator (compare with ===)
     */
    public function __construct(
        public int $folderId,
        public int $serviceId,
        public int $workspaceId,
        public array $ancestorIds,
        public ?string $createdBy,
        public ApiStudioTenant $tenant,
    ) {
    }
}
