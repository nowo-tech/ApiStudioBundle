<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bundle events of the Api Studio controllers (1.12): dispatched after the write was flushed, with ids and the opaque
 * actor id. Needs the optional `event_dispatcher`, {@see ApiStudioAuthorization} and {@see ApiStudioHistoryScope}
 * entries of the controller's service locator; without them nothing is dispatched (or the actor is null).
 */
trait StudioEventsTrait
{
    /**
     * Dispatches a bundle event when the host has an event dispatcher.
     */
    private function dispatchEvent(object $event): void
    {
        if ($this->container->has('event_dispatcher')) {
            /** @var EventDispatcherInterface $dispatcher */
            $dispatcher = $this->container->get('event_dispatcher');
            $dispatcher->dispatch($event);
        }
    }

    /**
     * Dispatches {@see ApiStudioResourceChangedEvent} after a flushed change (1.12). Ids are never null after a flush;
     * a missing id (hand-built entity in a unit test) skips the event.
     */
    private function resourceChanged(string $type, ?int $resourceId, ?int $workspaceId, string $action): void
    {
        if ($resourceId === null || $workspaceId === null) {
            return;
        }

        $this->dispatchEvent(new ApiStudioResourceChangedEvent($type, $resourceId, $workspaceId, $action, $this->currentActorId()));
    }

    /**
     * Opaque actor id of the signed-in user (null: anonymous), the same id history rows record.
     */
    private function currentActorId(): ?string
    {
        if (!$this->container->has(ApiStudioAuthorization::class)) {
            return null;
        }

        /** @var ApiStudioAuthorization $authorization */
        $authorization = $this->container->get(ApiStudioAuthorization::class);
        $scope         = $this->container->has(ApiStudioHistoryScope::class) ? $this->container->get(ApiStudioHistoryScope::class) : new ApiStudioHistoryScope();

        return $scope instanceof ApiStudioHistoryScope ? $scope->actorId($authorization->user()) : null;
    }
}
