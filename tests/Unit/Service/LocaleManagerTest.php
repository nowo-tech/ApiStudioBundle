<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LocaleManagerTest extends TestCase
{
    public function testExposesConfiguration(): void
    {
        $manager = new LocaleManager(new RequestStack(), ['en', 'es'], 'en');

        self::assertSame(['en', 'es'], $manager->getEnabledLocales());
        self::assertSame('en', $manager->getDefaultLocale());
        self::assertTrue($manager->isEnabled('es'));
        self::assertFalse($manager->isEnabled('de'));
    }

    public function testStoresEnabledLocaleInSession(): void
    {
        [$manager, $session] = $this->managerWithSession();

        $manager->setLocale('es');
        self::assertSame('es', $session->get(LocaleManager::SESSION_KEY));
        self::assertSame('es', $manager->resolveLocale());

        $manager->setLocale('de');
        self::assertSame('es', $manager->resolveLocale(), 'a disabled locale is ignored');
    }

    public function testFallsBackToDefaultForUnknownStoredLocale(): void
    {
        [$manager, $session] = $this->managerWithSession();
        $session->set(LocaleManager::SESSION_KEY, 'de');

        self::assertSame('en', $manager->resolveLocale());
    }

    public function testWithoutRequestOrSessionUsesDefault(): void
    {
        $stack   = new RequestStack();
        $manager = new LocaleManager($stack, ['en', 'es'], 'en');

        $manager->setLocale('es');
        self::assertSame('en', $manager->resolveLocale());

        $stack->push(new Request());
        $manager->setLocale('es');
        self::assertSame('en', $manager->resolveLocale());
    }

    /**
     * @return array{LocaleManager, Session}
     */
    private function managerWithSession(): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return [new LocaleManager($stack, ['en', 'es'], 'en'), $session];
    }
}
