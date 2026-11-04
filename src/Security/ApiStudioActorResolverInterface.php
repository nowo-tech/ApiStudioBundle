<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

/**
 * Host contract (`nowo_api_studio.history.actor_resolver`): the opaque id stored as `executed_by` on each history row
 * when `history.scope: user` (at most 64 characters). Prefer a public id over an e-mail address: the bundle tables
 * then hold no personal data.
 */
interface ApiStudioActorResolverInterface
{
    public function actorId(object $user): string;
}
