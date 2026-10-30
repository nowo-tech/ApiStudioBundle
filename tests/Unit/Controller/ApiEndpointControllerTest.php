<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\ApiEndpointController;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiRequestHistoryRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\EndpointTesterFormFactory;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Without the injected EndpointTesterFormFactory (e.g. a host that decorated the controller), the controller builds
 * one from the container's form factory.
 */
final class ApiEndpointControllerTest extends TestCase
{
    public function testTesterFormFactoryFallsBackToTheContainerFormFactory(): void
    {
        $controller = new ApiEndpointController(
            $this->createMock(ApiWorkspaceRepository::class),
            $this->createMock(ApiServiceRepository::class),
            $this->createMock(ApiEndpointRepository::class),
            $this->createMock(ApiRequestHistoryRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new LocaleManager(new RequestStack(), ['en'], 'en'),
            new EnvironmentContextBuilder(),
            $this->createMock(CsrfTokenManagerInterface::class),
        );
        $container = new Container();
        $container->set('form.factory', Forms::createFormFactory());
        $controller->setContainer($container);

        $factory = (new ReflectionMethod($controller, 'testerFormFactory'))->invoke($controller);

        self::assertInstanceOf(EndpointTesterFormFactory::class, $factory);
    }
}
