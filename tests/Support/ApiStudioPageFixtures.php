<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Form\ApiEndpointFormType;
use Nowo\ApiStudioBundle\Form\ApiEnvironmentFormType;
use Nowo\ApiStudioBundle\Form\ApiServiceFormType;
use Nowo\ApiStudioBundle\Form\ApiWorkspaceFormType;
use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Form\ImportFileFormType;
use Nowo\ApiStudioBundle\Service\EndpointTesterFormFactory;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestKernel;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\UiKitBundle\View\Pagination;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function assert;

/**
 * Page contexts of the REQ-UI-003 page contract test: the view models come from {@see ApiStudioPageFactory} (the
 * service the controllers use) and the FormViews from the real form types, both taken from the functional test
 * kernel; the other variables mirror the controllers. Entities are built in memory with fixed ids.
 */
final class ApiStudioPageFixtures
{
    private static ?TestKernel $kernel = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (test-only kernel shared by the data providers)

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(bool $empty = false): array
    {
        $workspaces = $empty ? [] : [self::workspace(), self::workspace(2, 'Billing', 'billing')];

        return self::factory()->context([
            'page'           => self::factory()->dashboard($empty ? 0 : 3, $empty ? 0 : 2, $empty ? 0 : 3),
            'workspaces'     => $workspaces,
            'workspaceCount' => $empty ? 0 : 3,
            'serviceCount'   => $empty ? 0 : 2,
            'endpointCount'  => $empty ? 0 : 3,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceIndex(bool $empty = false): array
    {
        $workspaces = $empty ? [] : [self::workspace(), self::workspace(2, 'Billing', 'billing')];

        return self::factory()->context([
            'page' => self::factory()->workspaceIndex($workspaces, new Pagination(1, 2, $empty ? 0 : 3, null, 'nowo_api_studio_workspace_index')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceShow(): array
    {
        $workspace = self::workspace();

        return self::factory()->context([
            'page'      => self::factory()->workspaceShow($workspace),
            'workspace' => $workspace,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceForm(bool $edit = true): array
    {
        $workspace = self::workspace();

        return self::factory()->context([
            'page' => self::factory()->workspaceForm(
                $edit ? $workspace : null,
                self::form(ApiWorkspaceFormType::class, $workspace),
                $edit ? self::csrfForm('/delete', 'delete1') : null,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceIndex(bool $empty = false): array
    {
        $workspace = $empty ? new ApiWorkspace('Empty', 'empty') : self::workspace();
        self::setId($workspace, 1);

        return self::factory()->context([
            'page' => self::factory()->serviceIndex($workspace, $workspace->getServices()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceShow(): array
    {
        $workspace = self::workspace();
        $service   = self::service($workspace);

        return self::factory()->context([
            'page'            => self::factory()->serviceShow($workspace, $service),
            'workspace'       => $workspace,
            'service'         => $service,
            'variableCatalog' => [
                ['key' => 'base_url', 'description' => 'API base URL', 'secret' => false],
                ['key' => 'token', 'description' => null, 'secret' => true],
            ],
            'environments' => $workspace->getEnvironments(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceForm(bool $edit = true): array
    {
        $workspace = self::workspace();
        $service   = self::service($workspace);

        return self::factory()->context([
            'page' => self::factory()->serviceForm(
                $workspace,
                $edit ? $service : null,
                self::form(ApiServiceFormType::class, $service),
                $edit ? self::csrfForm('/delete', 'delete10', ['confirmation' => $service->getName()]) : null,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function endpointIndex(bool $empty = false): array
    {
        $workspace = self::workspace();
        $service   = self::service($workspace, $empty ? 1 : 0);

        return self::factory()->context([
            'page' => self::factory()->endpointIndex($workspace, $service, $service->getEndpoints()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function endpointForm(bool $edit = true): array
    {
        $workspace = self::workspace();
        $service   = self::service($workspace);
        $endpoint  = self::endpoint($service);

        return self::factory()->context([
            'page' => self::factory()->endpointForm(
                $workspace,
                $service,
                $edit ? $endpoint : null,
                self::form(ApiEndpointFormType::class, $endpoint),
                $edit ? self::csrfForm('/delete', 'delete100') : null,
            ),
            'enabledLocales' => ['es', 'en'],
            'currentLocale'  => 'en',
        ]);
    }

    /**
     * Request console, with the variables of ApiEndpointController::show().
     *
     * @return array<string, mixed>
     */
    public static function endpointShow(bool $history = true): array
    {
        $workspace = self::workspace();
        $service   = self::service($workspace);
        $endpoint  = self::endpoint($service);
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::container()->get('form.factory');

        return self::factory()->context([
            'page'                             => self::factory()->endpointShow($workspace, $service, $endpoint),
            'workspace'                        => $workspace,
            'service'                          => $service,
            'endpoint'                         => $endpoint,
            'translation'                      => null,
            'enabledLocales'                   => ['es', 'en'],
            'defaultLocale'                    => 'en',
            'documentationByLocale'            => ['es' => ['title' => '', 'description' => ''], 'en' => ['title' => 'List users', 'description' => 'Paginated users.']],
            'loadRequestExampleId'             => null,
            'environments'                     => $workspace->getEnvironments(),
            'environmentMaps'                  => ['1' => ['base_url' => 'https://api.example.test']],
            'variableCatalog'                  => [['key' => 'base_url', 'description' => null, 'secret' => false]],
            'mergedHeaders'                    => ['Accept' => 'application/json'],
            'testerForm'                       => (new EndpointTesterFormFactory($formFactory))->create($endpoint, $service, $workspace->getEnvironments(), ['Accept' => 'application/json']),
            'history'                          => $history ? [self::historyItem(true), self::historyItem(false)] : [],
            'executeUrl'                       => '/execute/100',
            'csrfToken'                        => 'token-execute',
            'envSyncCsrfToken'                 => 'token-env-sync',
            'envSyncUrlTemplate'               => '/environments/__ENV__/variables',
            'saveRequestExampleUrl'            => '/examples/request',
            'saveResponseExampleUrl'           => '/examples/response',
            'deleteRequestExampleUrlTemplate'  => '/examples/request/__EXAMPLE__/delete',
            'deleteResponseExampleUrlTemplate' => '/examples/response/__EXAMPLE__/delete',
            'examplesCsrfToken'                => 'token-examples',
            'httpMethods'                      => HttpMethod::values(),
            'currentLocale'                    => 'en',
            'saveDocumentationUrl'             => '/documentation',
            'documentationCsrfToken'           => 'token-doc',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function environmentIndex(bool $empty = false): array
    {
        $workspace = $empty ? new ApiWorkspace('Empty', 'empty') : self::workspace();
        self::setId($workspace, 1);

        return self::factory()->context([
            'page' => self::factory()->environmentIndex($workspace, $workspace->getEnvironments()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function environmentShow(bool $empty = false): array
    {
        $workspace   = self::workspace();
        $environment = self::environment($workspace, $empty ? 1 : 0);

        return self::factory()->context([
            'page'        => self::factory()->environmentShow($workspace, $environment),
            'environment' => $environment,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function environmentForm(bool $edit = true): array
    {
        $workspace   = self::workspace();
        $environment = self::environment($workspace);

        return self::factory()->context([
            'page' => self::factory()->environmentForm(
                $workspace,
                $edit ? $environment : null,
                self::form(ApiEnvironmentFormType::class, $environment),
                $edit ? self::csrfForm('/delete', 'delete20') : null,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function importExportHub(): array
    {
        return self::factory()->context(['page' => self::factory()->importExportHub(self::workspace())]);
    }

    /**
     * @param 'environment'|'service'|'workspace' $scope
     *
     * @return array<string, mixed>
     */
    public static function import(string $scope = 'workspace'): array
    {
        $workspace = self::workspace();
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::container()->get('form.factory');
        $form        = $formFactory->create(ImportFileFormType::class, null, [
            'show_mode'              => $scope === 'environment',
            'show_postman_variables' => $scope === 'service',
        ])->createView();

        return self::factory()->context([
            'page' => self::factory()->import(
                $workspace,
                'import.openapi.title',
                $form,
                $scope === 'service' ? self::service($workspace) : null,
                $scope === 'environment' ? self::environment($workspace) : null,
            ),
        ]);
    }

    /**
     * Navigation tree of the fixture workspace (StudioNavigationProvider::buildTree() shape).
     *
     * @return list<array<string, mixed>>
     */
    public static function navigationTree(): array
    {
        return [
            [
                'id'       => 1,
                'name'     => 'Platform',
                'slug'     => 'platform',
                'url'      => '/nowo_api_studio_workspace_show?id=1',
                'services' => [
                    [
                        'id'        => 10,
                        'name'      => 'Users API',
                        'protocol'  => 'rest',
                        'url'       => '/nowo_api_studio_service_show?workspaceId=1&id=10',
                        'endpoints' => [
                            [
                                'id'               => 100,
                                'name'             => 'List users',
                                'method'           => 'GET',
                                'url'              => '/nowo_api_studio_endpoint_show?id=100',
                                'request_examples' => [['id' => 1000, 'name' => 'First page', 'url' => '/nowo_api_studio_endpoint_show?id=100&request_example=1000']],
                            ],
                        ],
                    ],
                ],
            ],
            ['id' => 2, 'name' => 'Billing', 'slug' => 'billing', 'url' => '/nowo_api_studio_workspace_show?id=2', 'services' => []],
        ];
    }

    /**
     * Form factory of the functional test kernel (FormKit option mergers wired).
     */
    public static function formFactory(): FormFactoryInterface
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::container()->get('form.factory');

        return $formFactory;
    }

    public static function factory(): ApiStudioPageFactory
    {
        /** @var ApiStudioPageFactory $factory */
        $factory = self::container()->get(ApiStudioPageFactory::class);

        return $factory;
    }

    /**
     * Workspace "Platform" (1) with the services Users API (10, endpoints List users 100 + Create user 101) and Legacy
     * SOAP (11, no endpoint), the environments dev (20, default, two variables) and prod (21, none).
     */
    public static function workspace(int $id = 1, string $name = 'Platform', string $slug = 'platform'): ApiWorkspace
    {
        $workspace = new ApiWorkspace($name, $slug);
        $workspace->setDescription('Internal platform APIs');
        self::setId($workspace, $id);

        $users = new ApiService('Users API', 'users');
        $users->setBaseUrl('{{base_url}}/v1');
        self::setId($users, 10);
        $workspace->addService($users);

        $list = new ApiEndpoint('List users', 'list_users');
        $list->setPath('/users');
        self::setId($list, 100);
        $example = new ApiRequestExample('First page');
        $example->setRequestBody('{"page": 1}');
        self::setId($example, 1000);
        $list->addRequestExample($example);
        $users->addEndpoint($list);

        $create = new ApiEndpoint('Create user', 'create_user');
        $create->setMethod(HttpMethod::Post);
        $create->setPath('/users');
        self::setId($create, 101);
        $users->addEndpoint($create);

        $soap = new ApiService('Legacy SOAP', 'legacy');
        self::setId($soap, 11);
        $workspace->addService($soap);

        $dev = new ApiEnvironment('dev', 'dev');
        $dev->setIsDefault(true);
        self::setId($dev, 20);
        $dev->addVariable(new ApiEnvironmentVariable('base_url', 'https://api.example.test'));
        $secret = new ApiEnvironmentVariable('token', 'REDACTED-TEST');
        $secret->setSecret(true);
        $dev->addVariable($secret);
        $workspace->addEnvironment($dev);

        $prod = new ApiEnvironment('prod', 'prod');
        self::setId($prod, 21);
        $workspace->addEnvironment($prod);

        return $workspace;
    }

    public static function service(ApiWorkspace $workspace, int $index = 0): ApiService
    {
        $service = $workspace->getServices()->get($index);
        assert($service instanceof ApiService);

        return $service;
    }

    public static function endpoint(ApiService $service): ApiEndpoint
    {
        foreach ($service->getEndpoints() as $endpoint) {
            return $endpoint;
        }

        throw new LogicException('The fixture service has no endpoint.');
    }

    public static function environment(ApiWorkspace $workspace, int $index = 0): ApiEnvironment
    {
        $environment = $workspace->getEnvironments()->get($index);
        assert($environment instanceof ApiEnvironment);

        return $environment;
    }

    /**
     * @param class-string<FormTypeInterface<mixed>> $type
     */
    private static function form(string $type, object $data): FormView
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::container()->get('form.factory');

        return $formFactory->create($type, $data)->createView();
    }

    /**
     * @param array<string, string> $hidden
     */
    private static function csrfForm(string $action, string $tokenId, array $hidden = []): FormView
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = self::container()->get('form.factory');
        $builder     = $formFactory->createNamedBuilder('', CsrfOnlyType::class, null, ['action' => $action, 'csrf_token_id' => $tokenId]);
        foreach ($hidden as $name => $value) {
            $builder->add($name, HiddenType::class, ['data' => $value, 'mapped' => false]);
        }

        return $builder->getForm()->createView();
    }

    /**
     * @return array{success: bool, responseStatus: int|null, executedAt: DateTimeImmutable, durationMs: int}
     */
    private static function historyItem(bool $success): array
    {
        return ['success' => $success, 'responseStatus' => $success ? 200 : null, 'executedAt' => new DateTimeImmutable('2026-10-30 10:00:00'), 'durationMs' => 42];
    }

    private static function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private static function container(): ContainerInterface
    {
        if (!self::$kernel instanceof TestKernel) {
            // The debug kernel (container rebuilt when the configuration changes) registers Symfony's error handler,
            // which turns warnings into exceptions: keep the handlers of the test run as they were.
            $errorHandler     = set_error_handler(null); // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test bootstrap; restored below)
            $exceptionHandler = set_exception_handler(null); // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test bootstrap; restored below)
            self::$kernel     = new TestKernel('test', true); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (test-only kernel)
            self::$kernel->boot();
            set_error_handler($errorHandler); // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (restores the test runner's handler)
            set_exception_handler($exceptionHandler); // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (restores the test runner's handler)
            $request = Request::create('/');
            $request->setSession(new Session(new MockArraySessionStorage()));
            /** @var ContainerInterface $testContainer */
            $testContainer = self::$kernel->getContainer()->get('test.service_container');
            /** @var RequestStack $stack */
            $stack = $testContainer->get('request_stack');
            $stack->push($request);
        }

        /** @var ContainerInterface $container */
        $container = self::$kernel->getContainer()->get('test.service_container');

        return $container;
    }
}
