<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestTenantResolver;
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
 * Host with tenants and workspace scopes (1.17): the organisation studio under /orgs/{org}/api-studio and the project
 * tab under /orgs/{org}/projects/{project}/api-studio (twin routes), the project layout, a configurable scope checker
 * and label provider, and secret references (a host secret source).
 */
final class ScopeTestKernel extends Kernel
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
        return sys_get_temp_dir() . '/nowo_api_studio_scope/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_scope/log';
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
        $container->extension('twig', ['strict_variables' => true, 'paths' => [__DIR__ . '/templates' => 'ScopeTest']]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///' . sys_get_temp_dir() . '/nowo_api_studio_scope/' . $this->environment . '.sqlite'],
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
                'locales'           => ['en'],
                'required_roles'    => [],
                'locale_scope'      => 'routes',
            ],
            'tenancy' => ['enabled' => true, 'resolver' => TestTenantResolver::class, 'sql_filter' => $this->environment !== 'nofilter'],
            // Environment "unscoped": tenant-level pages list unscoped workspaces only; "loose": any {project} value
            // reaches the resolver (exactness is then the bundle's, not the route requirement's); "nofilter": no SQL
            // filter (the explicit checks alone).
            'scope' => match ($this->environment) {
                'unscoped' => ['tenant_list' => 'unscoped'],
                'loose'    => ['path_requirements' => ['project' => '[^/]+']],
                default    => [],
            } + [
                'enabled'           => true,
                'resolver'          => TestScopeResolver::class,
                'access_checker'    => TestScopeAccessChecker::class,
                'label_provider'    => TestScopeLabelProvider::class,
                'path'              => '/orgs/{org}/projects/{project}/api-studio',
                'path_requirements' => ['project' => '[a-z0-9-]+'],
                'layout_template'   => '@ScopeTest/project_layout.html.twig',
            ],
            'scripts'  => ['mode' => 'disabled'],
            'secrets'  => ['source' => TestScopeSecretSource::class],
            'security' => ['access_roles' => [], 'allow_unauthenticated' => true, 'permission_checker' => TestPermissionChecker::class],
        ]);

        $services = $container->services();
        $services->set(TestTenantResolver::class);
        $services->set(TestPermissionChecker::class)->public();
        $services->set(TestScopeResolver::class)->public();
        $services->set(TestScopeAccessChecker::class)->public();
        $services->set(TestScopeLabelProvider::class)->public();
        $services->set(TestScopeSecretSource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
