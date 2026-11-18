<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Scope\ScopeAccessSubject;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * 1.17 scope checker double: grants everything except `$denied`, lists `$visible`, records what it was asked.
 */
final class RecordingScopeAccessChecker implements ScopeAccessCheckerInterface
{
    /** @var list<ApiStudioAction> */
    public array $denied = [];

    /** @var list<string>|null */
    public ?array $visible = null;

    /** @var list<array{string, string, int|null}> */
    public array $seen = [];

    public function isGranted(?object $user, ApiStudioAction $action, ScopeAccessSubject $subject): bool
    {
        $this->seen[] = [$action->value, $subject->scope->value, $subject->workspaceId];

        return !in_array($action, $this->denied, true);
    }

    public function visibleScopes(?object $user, ApiStudioTenant $tenant): ?array
    {
        return $this->visible;
    }
}
