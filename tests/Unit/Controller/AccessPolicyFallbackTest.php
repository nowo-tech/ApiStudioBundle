<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Nowo\ApiStudioBundle\Controller\AuthorizesTrait;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.16: a controller built by hand (no service locator entry) answers refusals with the default 403 policy.
 */
final class AccessPolicyFallbackTest extends TestCase
{
    public function testWithoutTheServiceTheDefaultPolicyAnswers403(): void
    {
        $controller = new class extends AbstractController {
            use AuthorizesTrait;

            public function policy(): ApiStudioAccessPolicy
            {
                return $this->accessPolicy();
            }
        };
        $controller->setContainer(new Container());

        self::assertFalse($controller->policy()->hidesDenials());
        self::assertSame(403, $controller->policy()->deniedJson('Endpoint not found.')->getStatusCode());
    }

    public function testTheStaticRouteHelperStillMatchesTheBundlePrefix(): void
    {
        self::assertTrue(ApiStudioAccessSubscriber::isApiStudioRoute(new Request(attributes: ['_route' => 'nowo_api_studio_dashboard'])));
        self::assertFalse(ApiStudioAccessSubscriber::isApiStudioRoute(new Request(attributes: ['_route' => 'app_share'])));
    }
}
