<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Bridge\SearchKit\ApiStudioSearchDefinition;
use Nowo\ApiStudioBundle\Doctrine\TablePrefixSubscriber;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\ScopeLabelProviderInterface;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Search\SearchAccess;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchHitMapper;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Test\QueryCountingMiddleware;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function random_bytes;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.18 SearchKit fixtures, shared by the SQLite tests and the real-database suite (MySQL 8.4, MariaDB 11.4,
 * PostgreSQL 16): an entity manager with tenancy, scopes and folders on behind SearchKit's query counter, one data set
 * with look-alike tenants and scopes, secrets in every column results must not show and LIKE wildcards in names, and
 * the definitions wired like the container does, with test doubles for the host ports.
 *
 * Data set (tenant `org:acme` unless said otherwise):
 * - "Payments" (unscoped) → service "Stripe gateway" (REST, secret base URL, auth, headers, scripts) → endpoints
 *   "Create charge" (POST `/v1/charges?api_key=…`), "List charges" (GET), "Refund charge" (POST, folder "Private"),
 *   "Charge stats" (GET, folder "Public");
 * - "Payments project A" (`project:a`) → "Ledger a" (GraphQL) → "Get ledger"; the same for `project:b`;
 * - "Payments look-alike scope" (`Project:a`: another case, another scope);
 * - "Promo 100% off", "Promo 1000 off", "rate_limit", "rateXlimit" (wildcards are literal);
 * - "Payments look-alike" in tenant `org:Acme`, "Payments other" in tenant `org:other`.
 */
trait SearchFixtures
{
    private QueryCounter $searchCounter;

    private SearchPermissionDouble $searchPermissions;

    private SearchFolderDouble $searchFolders;

    private RecordingScopeAccessChecker $searchScopes;

    private EventDispatcher $searchEvents;

    private RequestStack $searchRequests;

    private TokenStorage $searchTokens;

    private SearchTenantResolverDouble $searchTenants;

    private SearchFeatureGateDouble $searchGate;

    /** @var array<string, int> workspace / folder / service name => id */
    private array $searchIds = [];

    /**
     * An SQLite in-memory registry with the schema (skipped without pdo_sqlite).
     */
    private function sqliteSearchRegistry(): ScopeManagerRegistry
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        return $this->searchRegistry(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * A registry on $params (DBAL connection parameters), schema dropped and created, statements counted.
     *
     * @param array<string, mixed> $params
     */
    private function searchRegistry(array $params): ScopeManagerRegistry
    {
        $this->searchCounter     = new QueryCounter();
        $this->searchPermissions = new SearchPermissionDouble();
        $this->searchFolders     = new SearchFolderDouble();
        $this->searchScopes      = new RecordingScopeAccessChecker();
        $this->searchEvents      = new EventDispatcher();
        $this->searchRequests    = new RequestStack();
        $this->searchTokens      = new TokenStorage();
        $this->searchTenants     = new SearchTenantResolverDouble(null);
        $this->searchGate        = new SearchFeatureGateDouble(true);
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $config = $this->searchConfiguration();
        $config->setMiddlewares([new QueryCountingMiddleware($this->searchCounter)]);
        /** @phpstan-ignore argument.type */
        $connection = DriverManager::getConnection($params, $config);
        $registry   = new ScopeManagerRegistry($connection, fn (Connection $connection): EntityManagerInterface => $this->searchManager($connection));
        $em         = $this->searchEm($registry);
        $tool       = new SchemaTool($em);
        $metadata   = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $registry;
    }

    /**
     * Drops the data set's tables (with the folder tables, which the other suites' schemas do not know).
     */
    private function dropSearchSchema(ScopeManagerRegistry $registry): void
    {
        $em = $this->searchEm($registry);
        (new SchemaTool($em))->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $connection = $registry->getConnection();
        if ($connection instanceof Connection) {
            $connection->close();
        }
    }

    private function searchEm(ScopeManagerRegistry $registry): EntityManagerInterface
    {
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function searchConfiguration(): Configuration
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo_api_studio_test_proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return $config;
    }

    private function searchManager(Connection $connection): EntityManagerInterface
    {
        $em = new EntityManager($connection, $this->searchConfiguration(), new EventManager());
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixSubscriber('api_studio_', true, false, true, false, true));

        return $em;
    }

    /**
     * Writes the data set and clears the manager (the search reads the database, not the identity map).
     */
    private function seedSearchData(ScopeManagerRegistry $registry): void
    {
        $em       = $this->searchEm($registry);
        $payments = $this->searchWorkspace($em, 'Payments', 'acme', null);
        $payments->setDescription('SECRET_DESC')->setPreRequestScript('SECRET_SCRIPT');
        $stripe = (new ApiService('Stripe gateway', 'stripe'))
            ->setBaseUrl('https://user:SECRET_BASE@api.example.test')
            ->setProtocol(ApiProtocol::Rest)
            ->setAuthType(AuthType::Bearer)
            ->setAuthConfig(['token' => 'SECRET_TOKEN'])
            ->setDefaultHeaders(['Authorization' => 'SECRET_HEADER'])
            ->setPreRequestScript('SECRET_SCRIPT');
        $payments->addService($stripe);
        $em->persist($stripe);
        $private = (new ApiFolder('Private'))->setService($stripe);
        $public  = (new ApiFolder('Public'))->setService($stripe);
        $em->persist($private);
        $em->persist($public);
        $this->searchEndpoint($em, $stripe, 'Create charge', HttpMethod::Post, '/v1/charges?api_key=SECRET_QS');
        $this->searchEndpoint($em, $stripe, 'List charges', HttpMethod::Get, '/v1/charges');
        $this->searchEndpoint($em, $stripe, 'Refund charge', HttpMethod::Post, '/v1/refunds')->setFolder($private);
        $this->searchEndpoint($em, $stripe, 'Charge stats', HttpMethod::Get, '/v1/stats')->setFolder($public);

        foreach (['a' => 'Payments project A', 'b' => 'Payments project B'] as $project => $name) {
            $workspace = $this->searchWorkspace($em, $name, 'acme', 'project:' . $project);
            $ledger    = (new ApiService('Ledger ' . $project, 'ledger'))->setProtocol(ApiProtocol::Graphql);
            $workspace->addService($ledger);
            $em->persist($ledger);
            $this->searchEndpoint($em, $ledger, 'Get ledger', HttpMethod::Get, '/ledger');
        }
        $this->searchWorkspace($em, 'Payments look-alike scope', 'acme', 'Project:a');
        foreach (['Promo 100% off', 'Promo 1000 off', 'rate_limit', 'rateXlimit'] as $name) {
            $this->searchWorkspace($em, $name, 'acme', null);
        }
        $this->searchWorkspace($em, 'Payments look-alike', 'Acme', null);
        $this->searchWorkspace($em, 'Payments other', 'other', null);
        $em->flush();

        /** @var list<ApiWorkspace> $all */
        $all = $em->createQueryBuilder()->select('w')->from(ApiWorkspace::class, 'w')->getQuery()->getResult();
        foreach ($all as $workspace) {
            $this->searchIds[$workspace->getName()] = (int) $workspace->getId();
        }
        $this->searchIds['folder:Private']         = (int) $private->getId();
        $this->searchIds['service:Stripe gateway'] = (int) $stripe->getId();
        $em->clear();
    }

    /**
     * The SearchKit definition of $kind on $registry as the container wires it: tenancy and scopes on (the
     * {@see RecordingScopeAccessChecker} in `$this->searchScopes`), the permission, folder, tenant and gate doubles, a
     * main request with the viewer signed in ({@see self::searchActAs()}), the test routes.
     */
    private function searchDefinition(
        ScopeManagerRegistry $registry,
        SearchKind $kind,
        bool $scopedRoutes = true,
        ?ScopeLabelProviderInterface $labels = null,
        bool $folderChecker = true,
        string $tenantList = 'all',
        bool $scopes = true,
        bool $entryChain = true,
    ): ApiStudioSearchDefinition {
        $tenantContext = new ApiStudioTenantContext($this->searchRequests, true);
        $scopeContext  = new ScopeContext($this->searchRequests, $scopes);
        $authorization = new ApiStudioAuthorization($this->searchPermissions, $tenantContext, $this->searchTokens, $this->searchRequests, $scopeContext, $this->searchScopes, $tenantList === 'all');
        $folderAccess  = new FolderAccess($folderChecker ? $this->searchFolders : null, $authorization, $this->searchRequests);
        $workspaces    = new ApiWorkspaceRepository($registry);
        $entry         = new ApiStudioAccessSubscriber([], null, true, null, $this->searchTokens, true, $authorization, $this->searchGate);

        return new ApiStudioSearchDefinition(
            $kind,
            new SearchContextResolver($this->searchRequests, $tenantContext, $this->searchTenants, $scopeContext, $this->searchEvents),
            $authorization,
            $entryChain ? $entry : null,
            $workspaces,
            new ApiServiceRepository($registry),
            new ApiEndpointRepository($registry),
            new SearchAccess($authorization, $workspaces, new ApiFolderRepository($registry), $folderAccess),
            new SearchHitMapper(self::searchRouter(), $labels, $scopedRoutes),
            null,
            $tenantList === 'all',
        );
    }

    /**
     * SearchKit's provider around {@see self::searchDefinition()}, with `$this->searchEvents` as dispatcher.
     */
    private function searchProvider(ScopeManagerRegistry $registry, SearchKind $kind, mixed ...$options): EntitySearchProvider
    {
        /** @phpstan-ignore argument.type */
        $definition = $this->searchDefinition($registry, $kind, ...$options);

        return new EntitySearchProvider($definition, $this->searchEvents);
    }

    /**
     * Runs a search as the current viewer.
     */
    private function searchFor(EntitySearchProvider $provider, string $text, int $limit = 10, ?string $scope = null, string $viewer = 'ann'): ?SearchGroup
    {
        return $provider->search(SearchQuery::forUserIdentifier($viewer, $text, $limit, $scope));
    }

    /**
     * A new main request (a new HTTP request: nothing memoised) with $userIdentifier signed in and $tenant resolved.
     */
    private function searchActAs(?ApiStudioTenant $tenant, ?string $userIdentifier): void
    {
        while ($this->searchRequests->pop() !== null) {
        }
        $this->searchRequests->push(Request::create('/search'));
        $this->searchTokens->setToken($userIdentifier !== null ? new UsernamePasswordToken(new InMemoryUser($userIdentifier, null), 'main') : null);
        $this->searchTenants->tenant = $tenant;
        $this->searchGate->enabled   = true;
    }

    /**
     * Routes like the bundle's (tenant level and the `scope.path` twins under `/p/{project}`).
     */
    private static function searchRouter(): UrlGenerator
    {
        return new UrlGenerator(self::searchRoutes(), new RequestContext());
    }

    private static function searchRoutes(): RouteCollection
    {
        $routes = new RouteCollection();
        foreach (['' => '/studio', 'scoped_' => '/p/{project}/studio'] as $twin => $prefix) {
            $routes->add('nowo_api_studio_' . $twin . 'workspace_index', new Route($prefix . '/workspaces'));
            $routes->add('nowo_api_studio_' . $twin . 'workspace_show', new Route($prefix . '/workspaces/{id}'));
            $routes->add('nowo_api_studio_' . $twin . 'service_show', new Route($prefix . '/workspaces/{workspaceId}/services/{id}'));
            $routes->add('nowo_api_studio_' . $twin . 'endpoint_show', new Route($prefix . '/workspaces/{workspaceId}/services/{serviceId}/endpoints/{id}'));
        }

        return $routes;
    }

    private function searchWorkspace(EntityManagerInterface $em, string $name, string $tenant, ?string $scope): ApiWorkspace
    {
        $workspace = (new ApiWorkspace($name, 'w-' . bin2hex(random_bytes(6))))
            ->assignTenant(new ApiStudioTenant('org', $tenant))
            ->assignScope($scope !== null ? new WorkspaceScope($scope) : null);
        $em->persist($workspace);

        return $workspace;
    }

    private function searchEndpoint(EntityManagerInterface $em, ApiService $service, string $name, HttpMethod $method, string $path): ApiEndpoint
    {
        $endpoint = (new ApiEndpoint($name, 'e-' . bin2hex(random_bytes(6))))
            ->setService($service)
            ->setMethod($method)
            ->setPath($path)
            ->setHeaders(['X-Api-Key' => 'SECRET_HEADER'])
            ->setRequestBodyTemplate('{"secret": "SECRET_BODY"}')
            ->setPreRequestScript('SECRET_SCRIPT');
        $service->addEndpoint($endpoint);
        $em->persist($endpoint);

        return $endpoint;
    }
}
