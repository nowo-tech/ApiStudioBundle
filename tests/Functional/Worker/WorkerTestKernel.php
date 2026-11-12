<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestTenantResolver;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getmypid;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * 1.12.1 (REQ-RUNTIME-002): host of the worker-state test, built as production builds it (no debug collectors). A DDD
 * host: the Api Studio entities on a dedicated `api_studio` manager next to the host's default one, tenancy on (one
 * tenant per `{org}` of the URL), four users in memory logged in per request by the `X-Test-User` header, and a
 * Messenger bus with the probe handler on the Api Studio manager.
 */
final class WorkerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', false);
    }

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
        return self::baseDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_worker/' . (int) getmypid();
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
            'messenger'             => [
                'default_bus' => 'messenger.bus.default',
                'buses'       => ['messenger.bus.default' => []],
            ],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'    => ['url' => 'sqlite:///' . self::baseDir() . '/host.sqlite'],
                    'api_studio' => ['url' => 'sqlite:///' . self::baseDir() . '/studio.sqlite'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['HostApp' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => dirname(__DIR__) . '/App/Entity',
                        'prefix'    => 'Nowo\\ApiStudioBundle\\Tests\\Functional\\App\\Entity',
                    ]]],
                    'api_studio' => ['connection' => 'api_studio'],
                ],
            ],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['roles' => ['ROLE_USER']],
                'bob'   => ['roles' => ['ROLE_USER']],
                'carol' => ['roles' => ['ROLE_USER']],
                'dave'  => ['roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'users', 'custom_authenticators' => [HeaderUserAuthenticator::class]]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', [
            'enabled'        => true,
            'entity_manager' => 'api_studio',
            'ui'             => [
                'path'              => '/orgs/{org}/api-studio',
                'path_requirements' => ['org' => '[a-z]+'],
                'default_locale'    => 'en',
                'locales'           => ['en'],
                'required_roles'    => [],
            ],
            'tenancy'  => ['enabled' => true, 'resolver' => TestTenantResolver::class],
            'scripts'  => ['mode' => 'disabled'],
            'security' => ['access_roles' => ['ROLE_USER'], 'permission_checker' => TestPermissionChecker::class],
        ]);

        $services = $container->services();
        // The failing probe messages are logged as critical by Messenger: kept out of the test output.
        $services->set('logger', NullLogger::class);
        $services->set(TestTenantResolver::class);
        $services->set(TestPermissionChecker::class)->public();
        $services->set(HeaderUserAuthenticator::class);
        $services->set(WorkerProbeHandler::class)->public()
            ->arg('$entityManager', service('nowo_api_studio.entity_manager'))
            ->tag('messenger.message_handler', ['handles' => WorkerProbeMessage::class]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
