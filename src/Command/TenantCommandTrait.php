<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use RuntimeException;

/**
 * Shared checks of the `nowo:api-studio:tenant:*` commands: tenancy must be on and the workspaces must be ORM-managed.
 */
trait TenantCommandTrait
{
    private function tenantManager(): EntityManagerInterface
    {
        if (!$this->tenantContext->isEnabled()) {
            throw new RuntimeException('nowo_api_studio.tenancy.enabled is false: there are no tenants to manage.');
        }

        $manager = $this->registry->getManagerForClass(ApiWorkspace::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('No ORM entity manager handles the Api Studio workspaces.');
        }

        return $manager;
    }
}
