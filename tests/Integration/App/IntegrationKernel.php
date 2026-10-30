<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function sys_get_temp_dir;

/**
 * Application kernel for service-level integration tests (forms, Doctrine listeners, seeding, commands): the bundle
 * with its default configuration on an in-memory SQLite database. Pages are covered by tests/Functional.
 */
final class IntegrationKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new ApiStudioBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_integration/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_integration/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'integration-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router'                => ['utf8' => true],
            'csrf_protection'       => true,
            'form'                  => ['enabled' => true],
            'translator'            => ['fallbacks' => ['en']],
            'validation'            => ['enabled' => true],
            'php_errors'            => ['log' => false],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['lazy' => true, 'security' => false]],
        ]);
        $container->extension('nowo_api_studio', [
            'ui'       => ['locales' => ['en', 'es'], 'default_locale' => 'en'],
            'security' => ['access_roles' => ['ROLE_ADMIN']],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
