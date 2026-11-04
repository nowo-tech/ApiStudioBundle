<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * The workspaces the current request may see: those of the tenant (when tenancy is on), narrowed to `visibleIds`
 * when the permission checker lists them (null = all of the tenant).
 */
final readonly class ApiStudioScope
{
    /**
     * @param list<int>|null $visibleIds
     */
    public function __construct(
        public ApiStudioTenant $tenant,
        public bool $tenancy = false,
        public ?array $visibleIds = null,
    ) {
    }

    public static function unrestricted(): self
    {
        return new self(ApiStudioTenant::instance());
    }

    public function contains(ApiWorkspace $workspace): bool
    {
        if ($this->tenancy && !$workspace->getTenant()->equals($this->tenant)) {
            return false;
        }

        return $this->visibleIds === null || in_array($workspace->getId(), $this->visibleIds, true);
    }
}
