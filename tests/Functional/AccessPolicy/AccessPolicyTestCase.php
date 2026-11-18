<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\AccessPolicy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function array_values;
use function in_array;
use function preg_match;
use function sort;
use function sprintf;

/**
 * 1.16 `security.feature_gate` and `security.access_denied_response`, end to end on a host built without debug: every
 * route of the router goes through the gate and the policy, and under `404` a refusal is byte for byte the genuine
 * not-found of the same route.
 */
abstract class AccessPolicyTestCase extends WebTestCase
{
    /** The answer of a refusal in this environment. */
    protected const STATUS = 403;

    private const ENVIRONMENTS = [403 => 'policy403', 404 => 'policy404'];

    /** Candidate values for route placeholders, first match of the requirement wins. */
    private const PLACEHOLDER_VALUES = ['1', 'en', 'json', 'yaml', 'openapi', 'postman', 'x'];

    protected KernelBrowser $client;

    /** @var array{acme: int, beta: int, service: int, endpoint: int, environment: int} */
    protected array $ids;

    protected static function getKernelClass(): string
    {
        return AccessPolicyTestKernel::class;
    }

    /**
     * The kernel runs without debug, so its container is never rebuilt on change: start from a fresh cache.
     */
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(AccessPolicyTestKernel::baseDir() . '/cache/' . self::ENVIRONMENTS[static::STATUS]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient(['environment' => self::ENVIRONMENTS[static::STATUS], 'debug' => false]);
        $this->client->disableReboot();

        $em       = $this->em();
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $acme    = (new ApiWorkspace('Acme payments', 'payments'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $beta    = (new ApiWorkspace('Beta payments', 'payments'))->assignTenant(new ApiStudioTenant('org', 'beta'));
        $service = (new ApiService('Orders', 'orders'))->setBaseUrl('https://93.184.215.14');
        $acme->addService($service);
        $endpoint    = (new ApiEndpoint('List', 'list'))->setPath('/orders')->setService($service);
        $environment = new ApiEnvironment('Dev', 'dev');
        $acme->addEnvironment($environment);
        foreach ([$acme, $beta, $service, $endpoint, $environment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $this->ids = [
            'acme'        => (int) $acme->getId(),
            'beta'        => (int) $beta->getId(),
            'service'     => (int) $service->getId(),
            'endpoint'    => (int) $endpoint->getId(),
            'environment' => (int) $environment->getId(),
        ];
    }

    public function testEveryRouteGoesThroughTheGateAndThePolicy(): void
    {
        $routes = $this->studioRoutes();
        $all    = array_keys($this->router()->getRouteCollection()->all());
        sort($all);
        $expected = [...array_keys($routes), 'host_home'];
        sort($expected);
        self::assertSame($expected, $all, 'every route of the host is a studio route, except the host page');
        self::assertContains('host_studio_share', array_keys($routes));

        foreach ($routes as $name => $route) {
            $this->gate()->routes = [];
            $denied               = $this->call($route, $this->url($name, $route, 'acme'));
            self::assertSame(static::STATUS, $denied->getStatusCode(), $name . ': the gate is off');
            self::assertSame([$name], $this->gate()->routes, $name . ': the gate is consulted once');
            self::assertStringContainsString('no-store', (string) $denied->headers->get('Cache-Control'), $name);

            if (static::STATUS === 404) {
                $genuine = $this->call($route, $this->url($name, $route, 'nobody'));
                $this->assertSameNotFound($genuine, $denied, $name);
            }
        }

        $this->gate()->routes = [];
        $this->client->request('GET', '/orgs/acme/home', [], [], ['HTTP_X_TEST_USER' => 'alice']);
        self::assertResponseStatusCodeSame(403, 'a host page keeps its own answer');
        self::assertSame([], $this->gate()->routes, 'a host page is not gated');
    }

    public function testAnOpenGateLetsTheStudioThrough(): void
    {
        $this->gate()->enabled = true;
        $this->client->request('GET', '/orgs/acme/api-studio/', [], [], ['HTTP_X_TEST_USER' => 'alice']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Acme payments', (string) $this->client->getResponse()->getContent());
        self::assertSame(['org:acme'], $this->gate()->tenants);
    }

    public function testTheGateIsNotAskedForSomeoneWhoMayNotOpenTheStudio(): void
    {
        $this->checker()->denied = [ApiStudioAction::StudioOpen];
        $refused                 = $this->get('/orgs/acme/api-studio/');
        self::assertSame(404, $refused->getStatusCode(), 'studio.open denied: 404 in both policies');
        self::assertSame([], $this->gate()->routes, 'the plan of a studio one may not open is never revealed');
    }

    public function testPermissionRefusalsFollowThePolicy(): void
    {
        $this->gate()->enabled   = true;
        $this->checker()->denied = [ApiStudioAction::WorkspaceManage, ApiStudioAction::WorkspaceCreate, ApiStudioAction::WorkspaceImport];

        $refused = $this->get(sprintf('/orgs/acme/api-studio/workspaces/%d/edit', $this->ids['acme']));
        self::assertSame(static::STATUS, $refused->getStatusCode());
        $this->assertNotFoundLike($this->get('/orgs/acme/api-studio/workspaces/999999/edit'), $refused, 'workspace.manage');

        $refused = $this->get('/orgs/acme/api-studio/workspaces/new');
        self::assertSame(static::STATUS, $refused->getStatusCode(), 'workspace.create');
        $this->assertNotFoundLike($this->get('/orgs/nobody/api-studio/workspaces/new'), $refused, 'workspace.create');

        $refused = $this->get(sprintf('/orgs/acme/api-studio/workspaces/%d/import/openapi', $this->ids['acme']));
        self::assertSame(static::STATUS, $refused->getStatusCode(), 'workspace.import');
        $this->assertNotFoundLike($this->get('/orgs/acme/api-studio/workspaces/999999/import/openapi'), $refused, 'workspace.import');
    }

    public function testAnotherTenantsWorkspaceIsTheGenuineNotFound(): void
    {
        $this->gate()->enabled = true;
        $foreign               = $this->get(sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['beta']));
        $missing               = $this->get('/orgs/acme/api-studio/workspaces/999999');
        self::assertSame(404, $foreign->getStatusCode());
        $this->assertSameNotFound($missing, $foreign, 'tenancy mismatch');
    }

    public function testRoleRefusalOfASignedInUserFollowsThePolicyAndAnonymousKeepsTheFirewall(): void
    {
        $this->gate()->enabled = true;
        $refused               = $this->get('/orgs/acme/api-studio/', 'guest');
        self::assertSame(static::STATUS, $refused->getStatusCode());
        $this->assertNotFoundLike($this->get('/orgs/nobody/api-studio/', 'guest'), $refused, 'role');

        $anonymous = $this->get('/orgs/acme/api-studio/', null);
        self::assertContains($anonymous->getStatusCode(), [401, 403], 'anonymous: the firewall answers, never the policy');
    }

    public function testConsoleRefusalsAnswerTheirOwnNotFoundJson(): void
    {
        $this->gate()->enabled = true;
        $page                  = $this->client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d/services/%d/endpoints/%d', $this->ids['acme'], $this->ids['service'], $this->ids['endpoint']), [], [], ['HTTP_X_TEST_USER' => 'alice']);
        self::assertResponseIsSuccessful();
        $runToken  = (string) $page->filter('#api-studio-tester')->attr('data-csrf-token');
        $syncToken = (string) $page->filter('#api-studio-tester')->attr('data-env-sync-csrf');

        $this->checker()->denied = [ApiStudioAction::WorkspaceRun, ApiStudioAction::WorkspaceEdit];
        $routes                  = [
            '/orgs/acme/api-studio/execute/%d'              => $runToken,
            '/orgs/acme/api-studio/scripts/send-request/%d' => $runToken,
            '/orgs/acme/api-studio/scripts/variables/%d'    => $syncToken,
        ];
        foreach ($routes as $pattern => $token) {
            $refused = $this->postJson(sprintf($pattern, $this->ids['endpoint']), $token);
            self::assertSame(static::STATUS, $refused->getStatusCode(), $pattern);
            self::assertSame(static::STATUS === 404 ? '{"error":"Endpoint not found."}' : '{"error":"forbidden"}', $refused->getContent(), $pattern);
            $this->assertNotFoundLike($this->postJson(sprintf($pattern, 999999), $token), $refused, $pattern);
        }

        // A workspace one may not view: the genuine not-found of each endpoint, under both policies.
        $this->checker()->denied = [ApiStudioAction::WorkspaceView];
        foreach ($routes as $pattern => $token) {
            $hidden = $this->postJson(sprintf($pattern, $this->ids['endpoint']), $token);
            self::assertSame(404, $hidden->getStatusCode(), $pattern);
            self::assertSame('{"error":"Endpoint not found."}', $hidden->getContent(), $pattern);
        }
        self::assertSame(0, $this->em()->getRepository(ApiRequestHistory::class)->count([]), 'nothing was sent');
    }

    public function testHostRoutesAreGuardedAndCheckTheirWorkspace(): void
    {
        $share = sprintf('/orgs/acme/api-studio-share/%d', $this->ids['acme']);
        self::assertSame(static::STATUS, $this->get($share)->getStatusCode(), 'gate off');

        $this->gate()->enabled = true;
        $allowed               = $this->get($share);
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('share Acme payments', $allowed->getContent());
        self::assertSame('no-store, private', $allowed->headers->get('Cache-Control'));

        $this->checker()->denied = [ApiStudioAction::WorkspaceManage, ApiStudioAction::WorkspaceCreate];
        $refused                 = $this->get($share);
        self::assertSame(static::STATUS, $refused->getStatusCode(), 'the host controller refuses');
        $this->assertNotFoundLike($this->get('/orgs/acme/api-studio-share/999999'), $refused, 'host route');
        self::assertSame(404, $this->get(sprintf('/orgs/acme/api-studio-share/%d', $this->ids['beta']))->getStatusCode(), 'another tenant');

        $refused = $this->get('/orgs/acme/api-studio-import');
        self::assertSame(static::STATUS, $refused->getStatusCode(), 'host route declared workspace.create');
        $this->assertNotFoundLike($this->get('/orgs/nobody/api-studio-import'), $refused, 'host route workspace.create');
    }

    /**
     * Under `404` the refusal must be the genuine not-found: same status, body and headers that matter.
     */
    protected function assertNotFoundLike(Response $genuine, Response $refused, string $message): void
    {
        if (static::STATUS === 404) {
            $this->assertSameNotFound($genuine, $refused, $message);
        }
    }

    protected function assertSameNotFound(Response $genuine, Response $refused, string $message): void
    {
        self::assertSame(404, $genuine->getStatusCode(), $message . ': genuine not-found');
        self::assertSame(404, $refused->getStatusCode(), $message);
        self::assertSame($genuine->getContent(), $refused->getContent(), $message . ': same body');
        foreach (['Content-Type', 'Cache-Control', 'Pragma', 'WWW-Authenticate'] as $header) {
            self::assertSame($genuine->headers->get($header), $refused->headers->get($header), $message . ': ' . $header);
        }
    }

    /**
     * @return array<string, Route>
     */
    protected function studioRoutes(): array
    {
        /** @var ApiStudioRoutes $matcher */
        $matcher = self::getContainer()->get(ApiStudioRoutes::class);
        $routes  = [];
        foreach ($this->router()->getRouteCollection()->all() as $name => $route) {
            if ($matcher->isStudioRoute($name)) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    protected function url(string $name, Route $route, string $org): string
    {
        $parameters = [];
        foreach ($route->compile()->getPathVariables() as $variable) {
            if ($variable === 'org') {
                $parameters['org'] = $org;
                continue;
            }
            $requirement = $route->getRequirement($variable) ?? '[^/]+';
            foreach (self::PLACEHOLDER_VALUES as $value) {
                if (preg_match('#^(?:' . $requirement . ')$#u', $value) === 1) {
                    $parameters[$variable] = $value;
                    break;
                }
            }
        }

        return $this->router()->generate($name, $parameters);
    }

    protected function call(Route $route, string $url): Response
    {
        $methods = array_values($route->getMethods());
        $method  = $methods === [] || in_array('GET', $methods, true) ? 'GET' : $methods[0];
        $this->client->request($method, $url, [], [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_CSRF_TOKEN' => 'any', 'CONTENT_TYPE' => 'application/json'], '{}');

        return $this->client->getResponse();
    }

    protected function get(string $url, ?string $user = 'alice'): Response
    {
        $this->client->request('GET', $url, [], [], $user === null ? [] : ['HTTP_X_TEST_USER' => $user]);

        return $this->client->getResponse();
    }

    protected function postJson(string $url, string $token): Response
    {
        $this->client->request('POST', $url, [], [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], '{}');

        return $this->client->getResponse();
    }

    protected function gate(): TestFeatureGate
    {
        /** @var TestFeatureGate $gate */
        $gate = self::getContainer()->get(TestFeatureGate::class);

        return $gate;
    }

    protected function checker(): TestPermissionChecker
    {
        /** @var TestPermissionChecker $checker */
        $checker = self::getContainer()->get(TestPermissionChecker::class);

        return $checker;
    }

    protected function router(): RouterInterface
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        return $router;
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
