<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Scope\ScopeAccessSubject;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * Grants everything except `$denied[scope value]` actions; `$visible` is what tenant-level lists may show. One instance
 * per test (the kernel is not rebooted).
 */
final class TestScopeAccessChecker implements ScopeAccessCheckerInterface
{
    /** @var array<string, list<ApiStudioAction>> */
    public array $denied = [];

    /** @var list<string>|null */
    public ?array $visible = null;

    /** @var list<array{ApiStudioAction, ScopeAccessSubject}> */
    public array $calls = [];

    public function isGranted(?object $user, ApiStudioAction $action, ScopeAccessSubject $subject): bool
    {
        $this->calls[] = [$action, $subject];

        return !in_array($action, $this->denied[$subject->scope->value] ?? [], true);
    }

    public function visibleScopes(?object $user, ApiStudioTenant $tenant): ?array
    {
        return $this->visible;
    }
}
