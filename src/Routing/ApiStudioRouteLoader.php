<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Routing;

use Nowo\ApiStudioBundle\Controller\LocaleController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function dirname;

/**
 * Route loader behind `@ApiStudioBundle/Resources/config/routes.yaml` (type `nowo_api_studio`): the controllers'
 * attribute routes and the locale switch under `ui.path`, with `ui.path_requirements` applied to its placeholders
 * (`[^/]+` when none is configured). Requirements the host sets on its import still win.
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
        'EndpointExamplesController',
        'ApiExecuteController',
        'ImportExportController',
    ];

    /**
     * @param list<string> $placeholders
     * @param array<string, string> $requirements
     */
    public function __construct(
        private readonly string $path,
        private readonly string $localesRequirement,
        private readonly array $placeholders = [],
        private readonly array $requirements = [],
        ?string $env = null,
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

        $routes->addPrefix($this->path);
        $requirements = [];
        foreach ($this->placeholders as $name) {
            $requirements[$name] = $this->requirements[$name] ?? '[^/]+';
        }
        if ($requirements !== []) {
            $routes->addRequirements($requirements);
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
