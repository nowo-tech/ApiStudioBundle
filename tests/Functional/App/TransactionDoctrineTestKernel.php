<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Tests\Functional\TransactionDoctrineHostConflictTest;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
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
 * A host that registers `NowoTransactionDoctrineBundle` **and** chose `concurrency.conflict_status: 503` (1.21): the
 * combination the other test kernels do not cover. Its conflict responder answers at priority 64, before the bundle's
 * own subscriber (32), so a `StaleEditException` marked as a lock conflict would be answered by it as a retryable
 * `CONFLICT_001` — the defect fixed in 1.21, and the regression this kernel guards
 * ({@see TransactionDoctrineHostConflictTest}).
 *
 * The host routes of {@see ConflictProbeController} raise the failures that reach `kernel.exception` (a save that
 * answers on the page never gets that far), registered as Api Studio host routes so the bundle answers them.
 */
final class TransactionDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    /** The lock-conflict status and retry hint this host configured; a stale edit must use neither. */
    public const CONFLICT_STATUS = 503;

    public const RETRY_AFTER = 7;

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
        yield new NowoTransactionDoctrineBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_td_host/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_td_host/log';
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
            'dbal' => ['url' => 'sqlite:///' . sys_get_temp_dir() . '/nowo_api_studio_td_host/' . $this->environment . '.sqlite'],
            'orm'  => ['auto_mapping' => true],
        ]);
        $container->extension('security', [
            'firewalls' => ['main' => ['lazy' => true, 'security' => false]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', [
            'enabled'  => true,
            'ui'       => ['path' => '/api-studio', 'default_locale' => 'en', 'locales' => ['en'], 'required_roles' => []],
            'security' => [
                'access_roles'          => [],
                'allow_unauthenticated' => true,
                'host_routes'           => [
                    'probe_stale'    => 'studio.open',
                    'probe_wrapped'  => 'studio.open',
                    'probe_deadlock' => 'studio.open',
                ],
            ],
            'concurrency' => ['conflict_status' => self::CONFLICT_STATUS, 'retry_after' => self::RETRY_AFTER],
        ]);
        $container->extension('nowo_transaction_doctrine', []);
        $container->services()->set(ConflictProbeController::class)->public()->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
        $routes->add('probe_stale', '/probe/stale')->controller([ConflictProbeController::class, 'stale']);
        $routes->add('probe_wrapped', '/probe/wrapped')->controller([ConflictProbeController::class, 'wrapped']);
        $routes->add('probe_deadlock', '/probe/deadlock')->controller([ConflictProbeController::class, 'deadlock']);
    }
}
