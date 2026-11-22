<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioSubject;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use RuntimeException;

use function in_array;

/**
 * 1.19 (DDD-host checklist B8/B10): a host checker that decides on ids only — the subject's actor id and workspace id,
 * against grants keyed by `tenant key|actor id` — and never reads the user object. With `$refuseEntities` it fails
 * when a subject carries the entity, so a test proves a path is id-only.
 */
final class IdOnlyPermissionChecker implements ApiStudioPermissionCheckerInterface
{
    /** @var array<string, array<int, list<string>>> `tenant key|actor id` => workspace id => action values */
    public array $grants = [];

    /** @var list<int>|null */
    public ?array $visible = null;

    public bool $refuseEntities = false;

    public ?RuntimeException $failure = null;

    /** @var list<ApiStudioSubject> */
    public array $subjects = [];

    public function isGranted(?object $user, ApiStudioAction $action, ApiStudioSubject $subject): bool
    {
        $this->subjects[] = $subject;
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        if ($this->refuseEntities && $subject->workspace !== null) {
            throw new RuntimeException('The id-only checker was handed an entity.');
        }

        if ($subject->actorId === null || $subject->workspaceId === null) {
            return false;
        }

        return in_array($action->value, $this->grants[$subject->tenant->key() . '|' . $subject->actorId][$subject->workspaceId] ?? [], true);
    }

    public function visibleWorkspaceIds(?object $user, ApiStudioTenant $tenant): ?array
    {
        return $this->visible;
    }
}
