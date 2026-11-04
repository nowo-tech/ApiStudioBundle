<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Tenancy;

use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use RuntimeException;

/**
 * Records the created events; a workspace named "Boom" makes the listener fail (the creation must roll back).
 */
final class TestWorkspaceListener
{
    /** @var list<ApiWorkspaceCreatedEvent> */
    public array $created = [];

    public function onCreated(ApiWorkspaceCreatedEvent $event): void
    {
        if ($event->workspace->getName() === 'Boom') {
            throw new RuntimeException('Host listener failed.');
        }

        $this->created[] = $event;
    }
}
