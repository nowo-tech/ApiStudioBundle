<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

/**
 * Host contract (`nowo_api_studio.history.actor_resolver`): the opaque id stored as `executed_by` on each history row
 * when `history.scope: user` (at most 64 characters). Prefer a public id over an e-mail address: the bundle tables
 * then hold no personal data.
 *
 * 1.24 (GDPR erasure): derive the id from an immutable key (the user id), never from a field an identity eraser
 * anonymises. The UserKit eraser `api_studio` runs at priority 150 and asks this resolver with the user as it is then:
 * a host eraser with a higher priority that anonymises the field read here makes Api Studio miss the person's rows.
 */
interface ApiStudioActorResolverInterface
{
    public function actorId(object $user): string;
}
