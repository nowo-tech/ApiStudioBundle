<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function in_array;
use function is_string;

/**
 * Stores and resolves the active UI locale for API Studio screens.
 */
final class LocaleManager
{
    public const SESSION_KEY = '_nowo_api_studio_locale';

    /** @param list<string> $enabledLocales */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly array $enabledLocales,
        private readonly string $defaultLocale,
    ) {
    }

    /** @return list<string> */
    public function getEnabledLocales(): array
    {
        return $this->enabledLocales;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabledLocales, true);
    }

    public function setLocale(string $locale): void
    {
        if (!$this->isEnabled($locale)) {
            return;
        }

        $session = $this->getSession();
        if ($session instanceof SessionInterface) {
            $session->set(self::SESSION_KEY, $locale);
        }
    }

    public function resolveLocale(): string
    {
        $session = $this->getSession();
        if ($session instanceof SessionInterface) {
            $stored = $session->get(self::SESSION_KEY);
            if (is_string($stored) && $this->isEnabled($stored)) {
                return $stored;
            }
        }

        return $this->defaultLocale;
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session instanceof SessionInterface ? $session : null;
    }
}
