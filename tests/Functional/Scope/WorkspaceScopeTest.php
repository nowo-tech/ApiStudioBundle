<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioRouteActions;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\Tenancy\TestPermissionChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * 1.17 workspace scopes end to end: the project mount (twin routes, links, layout), lists filtered in the query, every
 * workspace lookup scoped (no IDOR from one project's prefix to another project's workspace), the scope checker
 * (404 / 403, deny by default), the tenant-level list with badges, creation in a scope and per-scope slugs.
 */
final class WorkspaceScopeTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var array{alpha: int, beta: int, org: int, raw: int, other: int, service: int, endpoint: int, environment: int} */
    private array $ids;

    protected static function getKernelClass(): string
    {
        return ScopeTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->boot('test');
    }

    private function boot(string $environment): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient(['environment' => $environment]);
        $this->client->catchExceptions(false);
        $this->client->disableReboot();

        $em       = $this->em();
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $acme  = new ApiStudioTenant('org', 'acme');
        $alpha = (new ApiWorkspace('Alpha payments', 'payments'))->assignTenant($acme)->assignScope(new WorkspaceScope('project:alpha'));
        // Same slug in another project of the same organisation: slugs are unique per (tenant, scope).
        $beta  = (new ApiWorkspace('Beta payments', 'payments'))->assignTenant($acme)->assignScope(new WorkspaceScope('project:beta'));
        $org   = (new ApiWorkspace('Org payments', 'payments'))->assignTenant($acme);
        $raw   = (new ApiWorkspace('Raw tools', 'tools'))->assignTenant($acme)->assignScope(new WorkspaceScope('project:raw'));
        $other = (new ApiWorkspace('Other org', 'payments'))->assignTenant(new ApiStudioTenant('org', 'zeta'))->assignScope(new WorkspaceScope('project:alpha'));

        $service = (new ApiService('Orders', 'orders'))->setBaseUrl('https://93.184.215.14');
        $beta->addService($service);
        $endpoint    = (new ApiEndpoint('List', 'list'))->setPath('/orders')->setService($service);
        $environment = new ApiEnvironment('Dev', 'dev');
        $beta->addEnvironment($environment);
        foreach ([$alpha, $beta, $org, $raw, $other, $service, $endpoint, $environment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $this->ids = [
            'alpha'       => (int) $alpha->getId(),
            'beta'        => (int) $beta->getId(),
            'org'         => (int) $org->getId(),
            'raw'         => (int) $raw->getId(),
            'other'       => (int) $other->getId(),
            'service'     => (int) $service->getId(),
            'endpoint'    => (int) $endpoint->getId(),
            'environment' => (int) $environment->getId(),
        ];
    }

    public function testTheProjectMountListsOnlyTheProjectsWorkspacesWithProjectLinksInTheProjectLayout(): void
    {
        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/workspaces');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Alpha payments', $html);
        self::assertStringNotContainsString('Beta payments', $html);
        self::assertStringNotContainsString('Org payments', $html);
        // Same scope value in another organisation: still another tenant.
        self::assertStringNotContainsString('Other org', $html);
        // Every bundle link stays in the project mount (twin routes + the {org} / {project} context defaults).
        self::assertStringContainsString(sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']), $html);
        self::assertStringNotContainsString('href="/orgs/acme/api-studio/', $html);
        // Rendered in the host's project layout, which gets the scope.
        self::assertStringContainsString('data-testid="project-tabs">Project project:alpha', $html);
        // No badge on a scoped list: every row is of that scope.
        self::assertStringNotContainsString('api-studio-workspace-scope', $html);

        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Alpha payments', $html);
        self::assertStringNotContainsString('Beta payments', $html);
    }

    public function testTheResolverGetsTheRouteAndTheTenantWithoutHttpTypes(): void
    {
        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/workspaces');
        $resolver = self::getContainer()->get(TestScopeResolver::class);
        self::assertInstanceOf(TestScopeResolver::class, $resolver);
        [$route, $tenant] = $resolver->seen[0];
        self::assertSame('nowo_api_studio_scoped_workspace_index', $route->routeName);
        self::assertTrue($route->scopedMount);
        self::assertSame(['org' => 'acme', 'project' => 'alpha'], $route->parameters);
        self::assertTrue($tenant->equals(new ApiStudioTenant('org', 'acme')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function betaUrls(): iterable
    {
        yield 'workspace' => ['/workspaces/%1$d'];
        yield 'workspace edit' => ['/workspaces/%1$d/edit'];
        yield 'services' => ['/workspaces/%1$d/services'];
        yield 'service' => ['/workspaces/%1$d/services/%2$d'];
        yield 'endpoint' => ['/workspaces/%1$d/services/%2$d/endpoints/%3$d'];
        yield 'environment' => ['/workspaces/%1$d/environments/%4$d'];
        yield 'variables export' => ['/workspaces/%1$d/export/variables.json'];
        yield 'import hub' => ['/workspaces/%1$d/import-export'];
    }

    #[DataProvider('betaUrls')]
    public function testAnotherProjectsWorkspaceAnswers404UnderThisProjectsPrefix(string $path): void
    {
        $url = sprintf($path, $this->ids['beta'], $this->ids['service'], $this->ids['endpoint'], $this->ids['environment']);

        // Reachable in its own project...
        $this->client->request('GET', '/orgs/acme/projects/beta/api-studio' . $url);
        self::assertLessThan(400, $this->client->getResponse()->getStatusCode());

        // ...the same id under project alpha's prefix is a 404, like a missing id (no IDOR, no oracle).
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio' . $url);
    }

    public function testATenantLevelWorkspaceIsNotReachableUnderAProjectPrefix(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['org']));
    }

    public function testTheSameScopeOfAnotherOrganisationIsNotReachable(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['other']));
    }

    public function testTheScopeComparisonIsExact(): void
    {
        // The resolver builds "project:Alpha" from {project}; the stored scope is "project:alpha".
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/projects/Alpha/api-studio/workspaces/%d', $this->ids['alpha']));
    }

    public function testAProjectWithoutAScopeAnswers404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/orgs/acme/projects/nobody/api-studio/workspaces');
    }

    public function testTheScopeCheckerDecidesPerScopeAndAction(): void
    {
        $checker = $this->scopeChecker();

        // studio.open denied in alpha: the whole project studio is a 404.
        $checker->denied = ['project:alpha' => [ApiStudioAction::StudioOpen]];
        try {
            $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/');
            self::fail('studio.open denied must answer 404.');
        } catch (NotFoundHttpException) {
        }

        // workspace.view denied: the workspace does not exist for the user; the list hides nothing else.
        $checker->denied = ['project:alpha' => [ApiStudioAction::WorkspaceView]];
        try {
            $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']));
            self::fail('workspace.view denied must answer 404.');
        } catch (NotFoundHttpException) {
        }

        // workspace.manage denied: viewable, but the edit page is a 403 and its action is hidden.
        $checker->denied = ['project:alpha' => [ApiStudioAction::WorkspaceManage]];
        $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(sprintf('/workspaces/%d/edit', $this->ids['alpha']), (string) $this->client->getResponse()->getContent());
        try {
            $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d/edit', $this->ids['alpha']));
            self::fail('workspace.manage denied must answer 403.');
        } catch (AccessDeniedHttpException) {
        }

        // workspace.create denied: the creation page is a 403 in that project only.
        $checker->denied = ['project:alpha' => [ApiStudioAction::WorkspaceCreate]];
        $this->client->request('GET', '/orgs/acme/projects/beta/api-studio/workspaces/new');
        self::assertResponseIsSuccessful();
        $this->expectException(AccessDeniedHttpException::class);
        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/workspaces/new');
    }

    public function testTheCheckerSeesTheScopeAndTheWorkspaceId(): void
    {
        $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']));
        self::assertResponseIsSuccessful();

        $seen = [];
        foreach ($this->scopeChecker()->calls as [$action, $subject]) {
            $seen[$action->value] = [$subject->scope->value, $subject->workspaceId, $subject->tenant->id];
        }
        self::assertSame(['project:alpha', null, 'acme'], $seen['studio.open'] ?? null);
        self::assertSame(['project:alpha', $this->ids['alpha'], 'acme'], $seen['workspace.view'] ?? null);
    }

    public function testTheTenantLevelListShowsEveryVisibleScopeWithBadges(): void
    {
        $labels = self::getContainer()->get(TestScopeLabelProvider::class);
        self::assertInstanceOf(TestScopeLabelProvider::class, $labels);

        $this->client->request('GET', '/orgs/acme/api-studio/workspaces');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Alpha payments', 'Beta payments', 'Org payments', 'Raw tools'] as $name) {
            self::assertStringContainsString($name, $html);
        }
        self::assertStringNotContainsString('Other org', $html);
        self::assertStringContainsString('>Project alpha</span>', $html);
        self::assertStringContainsString('>Project beta</span>', $html);
        // No label from the provider: the raw scope value.
        self::assertStringContainsString('>project:raw</span>', $html);
        // One provider call for the whole page (no N+1).
        self::assertSame(1, $labels->calls);
        self::assertSame(3, $labels->lastBatch);
        // Tenant-level pages keep the tenant layout and links.
        self::assertStringNotContainsString('project-tabs', $html);
        self::assertStringContainsString(sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['alpha']), $html);
    }

    public function testVisibleScopesNarrowTheTenantLevelListInTheQuery(): void
    {
        $checker          = $this->scopeChecker();
        $checker->visible = ['project:beta'];

        $this->client->request('GET', '/orgs/acme/api-studio/workspaces');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Beta payments', $html);
        self::assertStringContainsString('Org payments', $html);
        self::assertStringNotContainsString('Alpha payments', $html);

        // A scope the user may not list is not reachable from the tenant level either.
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['alpha']));
    }

    public function testNoVisibleScopeListsOnlyUnscopedWorkspaces(): void
    {
        $this->scopeChecker()->visible = [];

        $this->client->request('GET', '/orgs/acme/api-studio/workspaces');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Org payments', $html);
        self::assertStringNotContainsString('Alpha payments', $html);
        self::assertStringNotContainsString('Beta payments', $html);
    }

    public function testAScopedWorkspaceOpenedFromTheTenantLevelAsksTheScopeChecker(): void
    {
        $this->client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['alpha']));
        self::assertResponseIsSuccessful();

        $this->scopeChecker()->denied = ['project:alpha' => [ApiStudioAction::WorkspaceView]];
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['alpha']));
    }

    public function testTenantListUnscopedHidesScopedWorkspacesAtTenantLevel(): void
    {
        $this->boot('unscoped');

        $this->client->request('GET', '/orgs/acme/api-studio/workspaces');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Org payments', $html);
        self::assertStringNotContainsString('Alpha payments', $html);

        // Still reachable in its own project.
        $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']));
        self::assertResponseIsSuccessful();

        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d', $this->ids['alpha']));
    }

    public function testAWorkspaceCreatedInAProjectGetsItsScopeAndSlugsArePerScope(): void
    {
        // "payments" is taken in alpha, not in gamma.
        $this->createWorkspace('/orgs/acme/projects/gamma/api-studio', 'Gamma payments', 'payments');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/orgs/acme/projects/gamma/api-studio/workspaces/', $location);

        $created = $this->em()->getRepository(ApiWorkspace::class)->findOneBy(['name' => 'Gamma payments']);
        self::assertInstanceOf(ApiWorkspace::class, $created);
        self::assertSame('project:gamma', $created->getScope()?->value);
        self::assertTrue($created->getTenant()->equals(new ApiStudioTenant('org', 'acme')));

        $this->createWorkspace('/orgs/acme/projects/alpha/api-studio', 'Dup', 'payments');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAWorkspaceCreatedAtTenantLevelHasNoScope(): void
    {
        $this->createWorkspace('/orgs/acme/api-studio', 'Org tools', 'org-tools');
        self::assertResponseRedirects();

        $created = $this->em()->getRepository(ApiWorkspace::class)->findOneBy(['name' => 'Org tools']);
        self::assertInstanceOf(ApiWorkspace::class, $created);
        self::assertNull($created->getScope());
    }

    public function testEveryBundleRouteHasATwinWithTheSameAction(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $names = array_keys($router->getRouteCollection()->all());
        foreach (array_keys(ApiStudioRouteActions::MAP) as $route) {
            $twin = str_replace('nowo_api_studio_', 'nowo_api_studio_scoped_', $route);
            self::assertContains($twin, $names, $route);
            self::assertSame(ApiStudioRouteActions::actionFor($route), ApiStudioRouteActions::actionFor($twin));
            self::assertTrue(str_starts_with((string) $router->getRouteCollection()->get($twin)?->getPath(), '/orgs/{org}/projects/{project}/api-studio'));
        }
    }

    public function testNothingOfAScopedRequestLeaksIntoTheNextOneInTheSameKernel(): void
    {
        $this->client->request('GET', '/orgs/acme/projects/alpha/api-studio/workspaces');
        self::assertStringContainsString('Alpha payments', (string) $this->client->getResponse()->getContent());

        // Same kernel and container (worker mode, kernel kept alive): the next request is tenant level again.
        $this->client->request('GET', '/orgs/acme/api-studio/workspaces');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Org payments', $html);
        self::assertStringNotContainsString('project-tabs', $html);
        $context = self::getContainer()->get(ScopeContext::class);
        self::assertInstanceOf(ScopeContext::class, $context);
        self::assertNull($context->current());

        $this->client->request('GET', '/orgs/acme/projects/beta/api-studio/workspaces');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Beta payments', $html);
        self::assertStringNotContainsString('Alpha payments', $html);
        self::assertStringContainsString('/orgs/acme/projects/beta/api-studio/', $html);
    }

    public function testThePermissionCheckerStillApplies(): void
    {
        $permissions = self::getContainer()->get(TestPermissionChecker::class);
        self::assertInstanceOf(TestPermissionChecker::class, $permissions);
        $permissions->denied = [ApiStudioAction::WorkspaceView];

        try {
            $this->expectException(NotFoundHttpException::class);
            $this->client->request('GET', sprintf('/orgs/acme/projects/alpha/api-studio/workspaces/%d', $this->ids['alpha']));
        } finally {
            $permissions->denied = [];
        }
    }

    private function createWorkspace(string $mount, string $name, string $slug): void
    {
        $crawler = $this->client->request('GET', $mount . '/workspaces/new');
        $form    = $crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => $name,
            'api_workspace_form[slug]' => $slug,
        ]);
        // The form posts back to the same mount.
        self::assertStringStartsWith('http://localhost' . $mount . '/workspaces/new', $form->getUri());
        $this->client->submit($form);
    }

    private function scopeChecker(): TestScopeAccessChecker
    {
        $checker = self::getContainer()->get(TestScopeAccessChecker::class);
        self::assertInstanceOf(TestScopeAccessChecker::class, $checker);

        return $checker;
    }

    private function em(): EntityManagerInterface
    {
        $doctrine = self::getContainer()->get('doctrine');
        $em       = $doctrine instanceof ManagerRegistry ? $doctrine->getManager() : null;
        if (!$em instanceof EntityManagerInterface) {
            throw new RuntimeException('No entity manager.');
        }

        return $em;
    }
}
