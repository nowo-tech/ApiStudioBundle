<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\EntityManagerRecoverySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class EntityManagerRecoverySubscriberTest extends TestCase
{
    public function testSubscribesBetweenRouterAndFirewall(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 31]],
            EntityManagerRecoverySubscriber::getSubscribedEvents(),
        );
    }

    public function testClosedManagerLeftByPreviousRequestIsResetOnNextApiStudioRequest(): void
    {
        $open    = true;
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(ApiWorkspace::class)->willReturn($manager);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager', 'default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturnCallback(fn (?string $name): EntityManagerInterface => $name === 'default' ? $manager : $this->createMock(EntityManagerInterface::class));
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$open, $manager): EntityManagerInterface {
            $open = true;

            return $manager;
        });

        $subscriber = new EntityManagerRecoverySubscriber($registry);

        // Request 1: healthy manager, nothing to do.
        $subscriber->onKernelRequest($this->event('nowo_api_studio_dashboard'));

        // A failed flush closes the manager; no services_resetter runs before request 2.
        $open = false;
        $subscriber->onKernelRequest($this->event('nowo_api_studio_workspace_show'));

        // Request 3: manager healthy again, no second reset.
        $subscriber->onKernelRequest($this->event('nowo_api_studio_workspace_show'));
    }

    public function testIgnoresSubRequestsAndForeignRoutes(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagerForClass');
        $registry->expects(self::never())->method('resetManager');

        $subscriber = new EntityManagerRecoverySubscriber($registry);
        $subscriber->onKernelRequest($this->event('nowo_api_studio_dashboard', HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelRequest($this->event('app_home'));
        $subscriber->onKernelRequest($this->event(null));
    }

    public function testDoesNothingWhenManagerIsUnknown(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $registry->expects(self::never())->method('resetManager');

        (new EntityManagerRecoverySubscriber($registry))->onKernelRequest($this->event('nowo_api_studio_dashboard'));
    }

    public function testDoesNotResetWhenClosedManagerIsNotRegisteredByName(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($this->createMock(EntityManagerInterface::class));
        $registry->expects(self::never())->method('resetManager');

        (new EntityManagerRecoverySubscriber($registry))->onKernelRequest($this->event('nowo_api_studio_dashboard'));
    }

    private function event(?string $route, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }
}
