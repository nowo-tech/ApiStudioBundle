<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

/**
 * What an {@see ApiStudioAction} applies to: the tenant, and the workspace for workspace actions. The bundle never
 * passes a workspace of another tenant. Later minors may append fields (e.g. a folder).
 */
final readonly class ApiStudioSubject
{
    public function __construct(
        public ApiStudioTenant $tenant,
        public ?ApiWorkspace $workspace = null,
    ) {
    }
}
