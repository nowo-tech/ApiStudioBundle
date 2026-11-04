<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Tenancy;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
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

use function dirname;
use function sys_get_temp_dir;

/**
 * Host with tenants (1.11): the studio under /orgs/{org}/api-studio, one tenant per org, a configurable permission
 * checker, per-user history, Api Studio locale on its own routes only and scripts disabled.
 */
final class TenancyTestKernel extends Kernel
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
        return dirname(__DIR__) . '/App';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_tenancy/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_tenancy/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router'                => ['utf8' => true],
            'csrf_protection'       => true,
            'form'                  => ['enabled' => true],
            'translator'            => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
            'validation'            => ['enabled' => true],
            'php_errors'            => ['log' => false],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///' . sys_get_temp_dir() . '/nowo_api_studio_tenancy/test.sqlite'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['lazy' => true, 'security' => false]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', [
            'enabled' => true,
            'ui'      => [
                'path'              => '/orgs/{org}/api-studio',
                'path_requirements' => ['org' => '[a-z]+'],
                'default_locale'    => 'en',
                'locales'           => ['es', 'en'],
                'required_roles'    => [],
                'locale_scope'      => 'routes',
            ],
            'tenancy'  => ['enabled' => true, 'resolver' => TestTenantResolver::class],
            'history'  => ['scope' => 'user'],
            'scripts'  => ['mode' => 'disabled'],
            'security' => ['access_roles' => [], 'allow_unauthenticated' => true, 'permission_checker' => TestPermissionChecker::class],
        ]);

        $services = $container->services();
        $services->set(TestTenantResolver::class);
        $services->set(TestPermissionChecker::class)->public();
        $services->set(TestWorkspaceListener::class)->public()->tag('kernel.event_listener', ['event' => ApiWorkspaceCreatedEvent::class, 'method' => 'onCreated']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
