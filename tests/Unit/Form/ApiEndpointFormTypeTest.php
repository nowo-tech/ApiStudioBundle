<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Form;

use Nowo\ApiStudioBundle\Form\ApiEndpointFormType;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The translations collection shows one locale tab per enabled locale, or plain entries without a LocaleManager.
 */
final class ApiEndpointFormTypeTest extends TestCase
{
    public function testLocaleTabsFollowTheLocaleManager(): void
    {
        $tabs = new ReflectionMethod(ApiEndpointFormType::class, 'localeTabs');

        self::assertSame(['mode' => 'entries'], $tabs->invoke(new ApiEndpointFormType()));
        self::assertSame(
            ['mode' => 'entries', 'locales' => ['en', 'es'], 'default_locale' => 'es'],
            $tabs->invoke(new ApiEndpointFormType(new LocaleManager(new RequestStack(), ['en', 'es'], 'es'))),
        );
    }
}
