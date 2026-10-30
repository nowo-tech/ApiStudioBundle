<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\EventListener;

use Nowo\ApiStudioBundle\EventListener\LocaleSubscriber;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriberTest extends TestCase
{
    public function testSubscribesBeforeTheFrameworkLocaleListener(): void
    {
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', 20]], LocaleSubscriber::getSubscribedEvents());
    }

    public function testMainRequestGetsTheSessionLocale(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $manager = new LocaleManager($stack, ['en', 'es'], 'en');
        $manager->setLocale('es');
        $subscriber = new LocaleSubscriber($manager);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $sub = new Request();
        $subscriber->onKernelRequest(new RequestEvent($kernel, $sub, HttpKernelInterface::SUB_REQUEST));
        self::assertSame('en', $sub->getLocale(), 'sub-requests are left alone');

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('es', $request->getLocale());
    }
}
