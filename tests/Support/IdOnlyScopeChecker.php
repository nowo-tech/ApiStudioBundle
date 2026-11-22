<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\ApiStudioBundle\Scope\ScopeAccessSubject;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function in_array;

/**
 * 1.19 (DDD-host checklist B8): a scope checker deciding on ids only — the subject's actor id and scope, against
 * grants keyed by `actor id|scope` — never on the user object.
 */
final class IdOnlyScopeChecker implements ScopeAccessCheckerInterface
{
    /** @var array<string, list<string>> `actor id|scope` => action values */
    public array $grants = [];

    /** @var list<ScopeAccessSubject> */
    public array $subjects = [];

    public function isGranted(?object $user, ApiStudioAction $action, ScopeAccessSubject $subject): bool
    {
        $this->subjects[] = $subject;

        return $subject->actorId !== null && in_array($action->value, $this->grants[$subject->actorId . '|' . $subject->scope->value] ?? [], true);
    }

    public function visibleScopes(?object $user, ApiStudioTenant $tenant): ?array
    {
        return null;
    }
}
