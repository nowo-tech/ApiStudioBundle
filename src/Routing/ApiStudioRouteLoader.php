<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Routing;

use Nowo\ApiStudioBundle\Controller\LocaleController;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function dirname;

/**
 * Route loader behind `@ApiStudioBundle/Resources/config/routes.yaml` (type `nowo_api_studio`): the controllers'
 * attribute routes and the locale switch under `ui.path`, with `ui.path_requirements` applied to its placeholders
 * (`[^/]+` when none is configured). Requirements the host sets on its import still win.
 *
 * 1.17: with `scope.path` every route also has a twin under it, named `nowo_api_studio_scoped_<suffix>`, with
 * `scope.path_requirements` applied ({@see ApiStudioRoutes::canonical()}).
 */
final class ApiStudioRouteLoader extends Loader
{
    public const TYPE = 'nowo_api_studio';

    private const CONTROLLERS = [
        'DashboardController',
        'ApiWorkspaceController',
        'ApiServiceController',
        'ApiEnvironmentController',
        'EnvironmentVariablesController',
        'ApiEndpointController',
        'ApiFolderController',
        'ScriptSandboxController',
        'ScriptSendRequestController',
        'ScriptVariablesController',
        'EndpointExamplesController',
        'ApiExecuteController',
        'ImportExportController',
    ];

    /**
     * @param list<string> $placeholders
     * @param array<string, string> $requirements
     * @param string|null $scopedPath 1.17 `scope.path`: every route also gets a twin under it (`nowo_api_studio_scoped_*`)
     * @param list<string> $scopedPlaceholders
     * @param array<string, string> $scopedRequirements
     */
    public function __construct(
        private readonly string $path,
        private readonly string $localesRequirement,
        private readonly array $placeholders = [],
        private readonly array $requirements = [],
        ?string $env = null,
        private readonly ?string $scopedPath = null,
        private readonly array $scopedPlaceholders = [],
        private readonly array $scopedRequirements = [],
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach (self::CONTROLLERS as $controller) {
            $imported = $this->import(dirname(__DIR__) . '/Controller/' . $controller . '.php', 'attribute');
            if ($imported instanceof RouteCollection) {
                $routes->addCollection($imported);
            }
        }

        $routes->add('nowo_api_studio_locale_switch', new Route(
            '/locale/{_locale}',
            ['_controller' => LocaleController::class . '::switch'],
            ['_locale' => $this->localesRequirement],
            methods: ['GET'],
        ));

        $scoped = null;
        if ($this->scopedPath !== null && $this->scopedPath !== '') {
            // 1.17: twins under scope.path, built before the tenant-level prefix is applied.
            $scoped = new RouteCollection();
            foreach ($routes->all() as $name => $route) {
                $scoped->add(ApiStudioRoutes::scopedName($name), clone $route);
            }
            self::mount($scoped, $this->scopedPath, $this->scopedPlaceholders, $this->scopedRequirements);
        }

        self::mount($routes, $this->path, $this->placeholders, $this->requirements);
        if ($scoped instanceof RouteCollection) {
            $routes->addCollection($scoped);
        }

        return $routes;
    }

    /**
     * @param list<string> $placeholders
     * @param array<string, string> $requirements
     */
    private static function mount(RouteCollection $routes, string $path, array $placeholders, array $requirements): void
    {
        $routes->addPrefix($path);
        $resolved = [];
        foreach ($placeholders as $name) {
            $resolved[$name] = $requirements[$name] ?? '[^/]+';
        }
        if ($resolved !== []) {
            $routes->addRequirements($resolved);
        }
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
