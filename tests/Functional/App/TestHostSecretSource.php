<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretValue;
use Throwable;

use function in_array;

/**
 * Host secret store of the functional tests (1.13): `<source>/<id>` => value, optionally per actor (`<actor>|<source>/<id>`),
 * granted to the listed actors / workspaces only. Records every call; the test drives one instance.
 */
final class TestHostSecretSource implements ApiStudioSecretSourceInterface
{
    /** @var array<string, string> */
    public array $secrets = [];

    /** @var list<string|null>|null null = every actor */
    public ?array $allowedActors = null;

    /** @var list<int>|null null = every workspace */
    public ?array $allowedWorkspaces = null;

    /** @var list<ApiStudioSecretRef> */
    public array $calls = [];

    /** Thrown by {@see self::resolve()} when set (a failing store). */
    public ?Throwable $throw = null;

    public function resolve(ApiStudioSecretRef $ref): ?ApiStudioSecretValue
    {
        $this->calls[] = $ref;
        if ($this->throw instanceof Throwable) {
            throw $this->throw;
        }
        if ($this->allowedActors !== null && !in_array($ref->actorId, $this->allowedActors, true)) {
            return null;
        }
        if ($this->allowedWorkspaces !== null && !in_array($ref->workspaceId, $this->allowedWorkspaces, true)) {
            return null;
        }

        $value = $this->secrets[$ref->actorId . '|' . $ref->source . '/' . $ref->id] ?? $this->secrets[$ref->source . '/' . $ref->id] ?? null;

        return $value === null ? null : new ApiStudioSecretValue($value);
    }
}
