<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\IdOnlyHost;

use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;

/**
 * 1.19 (B10): the host records ownership from the committed id event — no entity, no user object — in its own table.
 */
final readonly class HostOwnershipListener
{
    public function __construct(
        private HostGrantStore $grants,
    ) {
    }

    public function __invoke(ApiWorkspaceCreatedCommittedEvent $event): void
    {
        if ($event->actorId !== null) {
            $this->grants->grant($event->tenant->key(), $event->actorId, $event->workspaceId);
        }
    }
}
