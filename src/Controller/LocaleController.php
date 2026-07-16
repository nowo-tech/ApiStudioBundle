<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Service\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function sprintf;

final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleManager $localeManager,
    ) {
    }

    public function switch(string $_locale, Request $request): Response
    {
        if (!$this->localeManager->isEnabled($_locale)) {
            throw new NotFoundHttpException(sprintf('Locale "%s" is not enabled for API Studio.', $_locale));
        }

        $this->localeManager->setLocale($_locale);

        $referer = $request->headers->get('referer');

        return $this->redirect($referer !== null && $referer !== '' ? $referer : '/');
    }
}
