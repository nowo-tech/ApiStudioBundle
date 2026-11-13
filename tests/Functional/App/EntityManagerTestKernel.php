<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Event\ApiStudioResourceChangedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedCommittedEvent;
use Nowo\ApiStudioBundle\Event\ApiWorkspaceCreatedEvent;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function getmypid;
use function hash;
use function serialize;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * Host kernel with a configurable Doctrine setup (1.12, `nowo_api_studio.entity_manager`): a standard host (one
 * default manager) or a DDD host with a dedicated Api Studio manager next to the host's own. One cache directory per
 * configuration and PHPUnit process (never deleted while the process runs: reused container classes point at it).
 */
final class EntityManagerTestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;
    /** Debug-only collectors the worker test resets by hand (no services resetter). */
    public const DEBUG_COLLECTORS = ['debug.stopwatch', 'logger', 'doctrine.debug_data_holder', 'debug.event_dispatcher', 'twig.profile'];

    private readonly string $cacheKey;

    /**
     * @param array<string, mixed> $doctrine doctrine configuration
     * @param array<string, mixed> $bundle nowo_api_studio configuration merged over the test defaults
     * @param bool $hostDefaultDriver the host gives its default manager's chain a catch-all default driver (what
     *                                DoctrineBundle 3.4 `auto_discover_entities` does)
     */
    public function __construct(
        private readonly array $doctrine,
        private readonly array $bundle = [],
        private readonly bool $hostDefaultDriver = false,
    ) {
        $this->cacheKey = hash('xxh128', serialize([$doctrine, $bundle, $hostDefaultDriver]));
        parent::__construct('test', true);
    }

    public static function mockResponses(): MockHttpClient
    {
        return new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 201]));
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
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_em/' . getmypid() . '/' . $this->cacheKey . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo_api_studio_em/' . getmypid() . '/' . $this->cacheKey . '/log';
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
        $container->extension('doctrine', $this->doctrine);
        $container->extension('security', [
            'firewalls' => ['main' => ['lazy' => true, 'security' => false]],
        ]);
        $container->extension('nowo_ui_kit', ['css_framework' => 'custom', 'icon_set' => 'bootstrap-icons']);
        $container->extension('nowo_api_studio', $this->bundle + [
            'enabled'  => true,
            'ui'       => ['path' => '/api-studio', 'default_locale' => 'en', 'locales' => ['en'], 'required_roles' => []],
            'security' => ['access_roles' => [], 'allow_unauthenticated' => true],
        ]);

        $services = $container->services();
        // Outbound requests of the tester never leave the process.
        $services->set('http_client', MockHttpClient::class)->factory([self::class, 'mockResponses'])->public();
        $recorder = $services->set(EventRecorder::class)->public()->arg('$entityManager', service('nowo_api_studio.entity_manager'));
        foreach ([ApiWorkspaceCreatedEvent::class, ApiWorkspaceCreatedCommittedEvent::class, ApiStudioResourceChangedEvent::class, ApiRequestExecutedEvent::class] as $event) {
            $recorder->tag('kernel.event_listener', ['event' => $event, 'method' => '__invoke']);
        }
    }

    public function process(ContainerBuilder $container): void
    {
        if ($this->hostDefaultDriver) {
            // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
            $container->getDefinition('doctrine.orm.default_metadata_driver')
                ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[__DIR__ . '/Entity'], true])]);
        }

        foreach (self::DEBUG_COLLECTORS as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@ApiStudioBundle/Resources/config/routes.yaml');
    }
}
