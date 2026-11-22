<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\IdOnlyHost;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;
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
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getmypid;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * 1.19 (DDD-host checklist B10): a DDD host whose Api Studio checks use ids only. The bundle's entities live on a
 * private `api_studio` manager (its own SQLite file); the host keeps its own default manager and connection, records
 * ownership from {@see ApiWorkspaceCreatedCommittedEvent} into its own table ({@see HostGrantStore}) and decides with
 * {@see HostGrantChecker} on the subject's actor id and workspace id. Tenancy is on (one tenant per `{org}`); users
 * log in per request with the `X-Test-User` header. Built without debug and without the services resetter, as a
 * FrankenPHP worker runs it (kernel reset false).
 */
final class IdOnlyHostKernel extends Kernel
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
        return sys_get_temp_dir() . '/nowo_api_studio_id_only/' . (int) getmypid();
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
            ]]]],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'users', 'custom_authenticators' => [HeaderUserAuthenticator::class]]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', [
            'enabled'        => true,
            'entity_manager' => 'api_studio',
            'ui'             => [
                'path'              => '/orgs/{org}/api-studio',
                'path_requirements' => ['org' => '[a-zA-Z]+'],
                'default_locale'    => 'en',
                'locales'           => ['en'],
                'required_roles'    => [],
            ],
            'tenancy'  => ['enabled' => true, 'resolver' => TestTenantResolver::class],
            'scripts'  => ['mode' => 'disabled'],
            'security' => ['access_roles' => ['ROLE_USER'], 'permission_checker' => HostGrantChecker::class],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(TestTenantResolver::class);
        $services->set(HeaderUserAuthenticator::class);
        $services->set(HostGrantStore::class)->public()->arg('$connection', service('doctrine.dbal.default_connection'));
        $services->set(HostGrantChecker::class)->autowire();
        $services->set(HostOwnershipListener::class)->autowire()
            ->tag('kernel.event_listener', ['event' => ApiWorkspaceCreatedCommittedEvent::class, 'method' => '__invoke']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
