<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestTenantResolver;
use Nowo\ApiStudioBundle\Tests\Functional\Worker\HeaderUserAuthenticator;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function sys_get_temp_dir;

/**
 * 1.16 host of the 403 / 404 policy tests, built without debug (the error pages a visitor gets): tenants per `{org}`,
 * users logged in per request by `X-Test-User` (alice may use the studio, guest has no role), the permission checker
 * and feature gate doubles, folders and sandboxed scripts on (every route is live) and two host routes. Environment
 * `policy403` keeps the default answer, `policy404` hides refusals.
 */
final class AccessPolicyTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const HOST_ROUTES = ['host_studio_share' => 'studio.open', 'host-studio-import' => 'workspace.create'];

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

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_policy';
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/log';
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
            'dbal' => ['url' => 'sqlite:///' . self::baseDir() . '/' . $this->environment . '.sqlite'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['roles' => ['ROLE_USER']],
                'guest' => ['roles' => ['ROLE_GUEST']],
            ]]]],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'users', 'custom_authenticators' => [HeaderUserAuthenticator::class]]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', [
            'enabled' => true,
            'ui'      => [
                'path'              => '/orgs/{org}/api-studio',
                'path_requirements' => ['org' => '[a-z]+'],
                'default_locale'    => 'en',
                'locales'           => ['en', 'es'],
                'required_roles'    => [],
            ],
            'tenancy'  => ['enabled' => true, 'resolver' => TestTenantResolver::class],
            'folders'  => ['enabled' => true],
            'scripts'  => ['mode' => 'sandbox'],
            'security' => [
                'access_roles'           => ['ROLE_USER'],
                'permission_checker'     => TestPermissionChecker::class,
                'feature_gate'           => TestFeatureGate::class,
                'access_denied_response' => $this->environment === 'policy404' ? 404 : 403,
                // Environment "policy_typo": a declared host route the router does not have (the warm-up fails).
                'host_routes' => $this->environment === 'policy_typo' ? [...self::HOST_ROUTES, 'host_studio_typo' => 'studio.open'] : self::HOST_ROUTES,
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(TestTenantResolver::class);
        $services->set(TestPermissionChecker::class)->public();
        $services->set(TestFeatureGate::class)->public();
        $services->set(HeaderUserAuthenticator::class);
        $services->set(HostShareController::class)->autowire()->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
        $routes->add('host_studio_share', '/orgs/{org}/api-studio-share/{id}')
            ->controller(HostShareController::class)
            ->requirements(['org' => '[a-z]+', 'id' => '\d+']);
        $routes->add('host-studio-import', '/orgs/{org}/api-studio-import')
            ->controller([self::class, 'hostImport'])
            ->requirements(['org' => '[a-z]+']);
        // A host page that is not part of the studio: never touched by the gate or the policy.
        $routes->add('host_home', '/orgs/{org}/home')
            ->controller([self::class, 'hostHome'])
            ->requirements(['org' => '[a-z]+']);
    }

    public static function hostImport(): Response
    {
        return new Response('import');
    }

    public static function hostHome(): Response
    {
        return new Response('', Response::HTTP_FORBIDDEN);
    }
}
