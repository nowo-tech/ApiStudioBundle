<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Controller\ApiStudioPageTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * A controller using the page trait, exposing its 1.17 link helpers to the tests.
 */
final class StudioUrlsController extends AbstractController
{
    use ApiStudioPageTrait;

    public function url(string $route): string
    {
        return $this->generateUrl($route);
    }

    public function route(string $route): string
    {
        return $this->studioRoute($route);
    }
}
