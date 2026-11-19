<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit;

use Nowo\ApiStudioBundle\Event\ApiStudioSearchContextEvent;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\SearchLabelsDouble;
use Nowo\ApiStudioBundle\Tests\Support\SearchScenarios;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

use function array_map;
use function sprintf;

/**
 * 1.18 SearchKit groups on SQLite: the shared scenarios (also run on MySQL / MariaDB / PostgreSQL by the
 * SearchKitDatabase suite), the host hook, scope labels, links, recents and the gates.
 */
final class ApiStudioSearchTest extends TestCase
{
    use SearchScenarios;
    use SkipsWithoutSearchKit;

    private ScopeManagerRegistry $registry;

    protected function setUp(): void
    {
        $this->skipWithoutSearchKit();
        $this->registry = $this->sqliteSearchRegistry();
        $this->seedSearchData($this->registry);
    }

    public function testOrgAndProjectVisibility(): void
    {
        $this->assertOrgAndProjectVisibility($this->registry);
    }

    public function testTenantsNeverLeak(): void
    {
        $this->assertTenantsNeverLeak($this->registry);
    }

    public function testAccessDenyHidesRows(): void
    {
        $this->assertAccessDenyHidesRows($this->registry);
    }

    public function testFoldersNarrowEndpoints(): void
    {
        $this->assertFoldersNarrowEndpoints($this->registry);
    }

    public function testLikeIsEscaped(): void
    {
        $this->assertLikeIsEscaped($this->registry);
    }

    public function testEndpointsByNamePathAndMethod(): void
    {
        $this->assertEndpointsByNamePathAndMethod($this->registry);
    }

    public function testQueryBudget(): void
    {
        $this->assertQueryBudget($this->registry);
    }

    public function testEntryChainRefusalsAreNull(): void
    {
        $workspaces = $this->searchProvider($this->registry, SearchKind::Workspace);
        self::assertNotNull($this->searchFor($workspaces, 'payments'));

        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $this->searchGate->enabled = false;
        self::assertNull($this->searchFor($workspaces, 'payments'), 'feature gate');

        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $this->searchPermissions->denied = [ApiStudioAction::StudioOpen];
        self::assertNull($this->searchFor($workspaces, 'payments'), 'studio.open');
        $this->searchPermissions->denied = [];

        // In a scope, the scope checker's studio.open decides too.
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $this->searchScopes->denied = [ApiStudioAction::StudioOpen];
        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, scopedRoutes: false), 'payments', 10, 'project:a'));
        $this->searchScopes->denied = [];

        // No tenant, nobody signed in, another viewer than the signed-in one, no access subscriber.
        $this->searchActAs(null, 'ann');
        self::assertNull($this->searchFor($workspaces, 'payments'));
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), null);
        self::assertNull($this->searchFor($workspaces, 'payments'));
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        self::assertNull($this->searchFor($workspaces, 'payments', 10, null, 'bob'));
        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, entryChain: false), 'payments'));

        // Visible ids of the permission checker narrow in SQL ([] = none).
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $this->searchPermissions->visibleIds = [$this->searchIds['Payments project A']];
        self::assertSame(['Payments project A'], $this->titles($this->searchFor($workspaces, 'payments')));
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $this->searchPermissions->visibleIds = [];
        self::assertNull($this->searchFor($workspaces, 'payments'));
    }

    public function testSearchScopeIdsAreClientInput(): void
    {
        $workspaces = $this->searchProvider($this->registry, SearchKind::Workspace, scopedRoutes: false);
        // A scope the bundle does not know shows nothing; scopes off: any scope id shows nothing.
        self::assertNull($this->searchFor($workspaces, 'payments', 10, 'unknown:x'));
        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, scopes: false), 'payments', 10, 'project:a'));
        // Scopes off, unscoped: every workspace of the tenant (no scope filter).
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        self::assertSame(['Payments', 'Payments look-alike scope', 'Payments project A', 'Payments project B'], $this->titles($this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, scopes: false), 'payments')));
    }

    public function testHostListenerMapsTheScopeAndGivesLinkParameters(): void
    {
        $seen = [];
        $this->searchEvents->addListener(ApiStudioSearchContextEvent::class, static function (ApiStudioSearchContextEvent $event) use (&$seen): void {
            $seen[] = [$event->kind, $event->searchScope, $event->tenant()?->key(), $event->workspaceScope()?->value];
            if ($event->searchScope === '0b9c') {
                $event->setWorkspaceScope(new WorkspaceScope('project:a'));
            }
            $event->setRouteParameters(['project' => 'proj-a', 'id' => 'ignored', 7 => 'dropped', 'flag' => true, 'list' => []]);
            $event->excludeKind(SearchKind::Service);
        });

        $group = $this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace), 'payments', 10, '0b9c');
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['Payments project A'], $this->titles($group));
        // Audit VG-1: the tenant-level show page (tenant_list: all), with the tenant-level search's link parameters.
        self::assertSame(sprintf('/studio/workspaces/%d?project=proj-a&flag=1', $this->searchIds['Payments project A']), $group->items[0]->url);
        self::assertSame('/p/proj-a/studio/workspaces?flag=1', $group->moreUrl);
        self::assertNull($group->items[0]->badge, 'no badge inside the scope itself');
        self::assertSame('api_studio_workspaces', $group->key);
        // Once per group, viewer and scope id of the request, however often the provider asks.
        self::assertSame([[SearchKind::Workspace, '0b9c', 'org:acme', '0b9c'], [SearchKind::Workspace, null, 'org:acme', null]], $seen);

        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Service), 'ledger'), 'excluded group');
    }

    public function testHostListenerCanDenyOrSwitchTheTenant(): void
    {
        $this->searchEvents->addListener(ApiStudioSearchContextEvent::class, static function (ApiStudioSearchContextEvent $event): void {
            if ($event->kind === SearchKind::Endpoint) {
                $event->deny();
            }
            if ($event->kind === SearchKind::Service) {
                $event->setTenant(new ApiStudioTenant('org', 'other'));
            }
        });

        self::assertSame(4, $this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace), 'payments')?->total);
        // Same request: the authorization memo of org:acme does not answer for org:other.
        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Service), 'stripe'));
        self::assertNull($this->searchFor($this->searchProvider($this->registry, SearchKind::Endpoint), 'charge'));
    }

    public function testScopeLabelsAreOneCallPerGroup(): void
    {
        $labels = new SearchLabelsDouble();
        $group  = $this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, labels: $labels), 'payments');
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(1, $labels->calls);
        $badges = [];
        foreach ($group->items as $item) {
            $badges[$item->title] = $item->badge?->label;
        }
        self::assertSame(['Payments' => null, 'Payments look-alike scope' => 'Project Project:a', 'Payments project A' => 'Project project:a', 'Payments project B' => 'Project project:b'], $badges);
        self::assertSame('/studio/workspaces', $group->moreUrl);

        // Without a label provider the raw scope value is the badge.
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $group = $this->searchFor($this->searchProvider($this->registry, SearchKind::Service), 'ledger');
        self::assertSame(['project:a', 'project:b'], array_map(static fn (SearchItem $item): ?string => $item->badge?->label, $group->items ?? []));
        self::assertSame('Payments project A · GraphQL', $group?->items[0]->subtitle);
    }

    public function testALinkTheRouterCannotBuildFailsTheGroup(): void
    {
        // tenant_list: unscoped links to the scoped twin, which needs {project}; no listener gives it. SearchKit's engine
        // logs and drops the group.
        $this->expectException(MissingMandatoryParametersException::class);
        $this->searchFor($this->searchProvider($this->registry, SearchKind::Workspace, tenantList: 'unscoped'), 'payments', 10, 'project:a');
    }

    public function testRevalidatedRecents(): void
    {
        $provider = $this->searchProvider($this->registry, SearchKind::Workspace);
        $id       = $this->searchIds['Payments'];
        $url      = '/studio/workspaces/' . $id;
        $item     = $provider->resolve(new SearchReference($url, 'api_studio_workspaces', 'nowo_api_studio_workspace_show', ['id' => (string) $id]), 'ann', null);
        self::assertInstanceOf(SearchItem::class, $item);
        self::assertSame('Payments', $item->title);

        $other = $this->searchIds['Payments other'];
        self::assertNull($provider->resolve(new SearchReference('/studio/workspaces/' . $other, 'api_studio_workspaces', 'nowo_api_studio_workspace_show', ['id' => $other]), 'ann', null), 'another tenant');
        foreach ([['nowo_api_studio_service_show', $id], ['nowo_api_studio_workspace_show', '01'], ['nowo_api_studio_workspace_show', 0], ['nowo_api_studio_workspace_show', 'x'], ['nowo_api_studio_workspace_show', null]] as [$route, $value]) {
            self::assertNull($provider->resolve(new SearchReference($url, 'api_studio_workspaces', $route, ['id' => $value]), 'ann', null));
        }
        // The scoped twin of the route is the same page.
        self::assertNotNull($provider->resolve(new SearchReference($url, 'api_studio_workspaces', 'nowo_api_studio_scoped_workspace_show', ['id' => $id]), 'ann', null));

        // Audit VG-1: a recent from a project-scoped search round-trips: the URL the search returned is the one resolve()
        // rebuilds without a scope. The host gives {project} for the scoped search only (its "see all" is the twin).
        $this->searchEvents->addListener(ApiStudioSearchContextEvent::class, static function (ApiStudioSearchContextEvent $event): void {
            if ($event->searchScope !== null) {
                $event->setRouteParameters(['project' => 'proj-a']);
            }
        });
        foreach ([SearchKind::Workspace, SearchKind::Service, SearchKind::Endpoint] as $kind) {
            $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
            $scoped = $this->searchProvider($this->registry, $kind);
            $found  = $this->searchFor($scoped, $kind === SearchKind::Workspace ? 'payments' : 'ledger', 10, 'project:a');
            self::assertInstanceOf(SearchGroup::class, $found);
            $hit                  = $found->items[0];
            [$route, $parameters] = $this->routeOf($hit->url);
            $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
            $again = $this->searchProvider($this->registry, $kind)->resolve(new SearchReference($hit->url, $found->key, $route, $parameters), 'ann', null);
            self::assertInstanceOf(SearchItem::class, $again, $kind->value);
            self::assertSame($hit->url, $again->url);
        }

        $services  = $this->searchProvider($this->registry, SearchKind::Service);
        $endpoints = $this->searchProvider($this->registry, SearchKind::Endpoint);
        self::assertNull($services->resolve(new SearchReference('/x', 'api_studio_services', 'nowo_api_studio_service_show', ['id' => 999_999]), 'ann', null));
        self::assertNull($endpoints->resolve(new SearchReference('/x', 'api_studio_endpoints', 'nowo_api_studio_endpoint_show', ['id' => 999_999]), 'ann', null));
    }

    public function testDefinitionEdges(): void
    {
        $definition = $this->searchDefinition($this->registry, SearchKind::Endpoint);
        self::assertSame('search.group.endpoints', $definition->label(SearchQuery::forUserIdentifier('ann', 'x', 5)));
        self::assertSame('api_studio.endpoint', $definition->subjectType());
        self::assertSame(0, $definition->subjectId(new RuntimeException()));
        self::assertSame(0, $definition->subjectId(['eid' => 'x']));
        $query = SearchQuery::forUserIdentifier('ann', 'x', 5);
        // Another tenant than the context's: nothing (the provider never asks this, the hooks still refuse).
        self::assertFalse($definition->featureEnabled('org:other', $query));
        self::assertFalse($definition->featureEnabled('not a tenant', $query));
        self::assertSame([], $definition->filterVisible([['eid' => 1, 'wid' => 1]], 'org:other', $query));
        self::assertSame([], $definition->baseQuery('org:other', $query)->getQuery()->getArrayResult());
        /* @phpstan-ignore argument.type (a row SearchKit never passes) */
        self::assertSame([], $definition->filterVisible(['not a row'], 'org:acme', $query));
        self::assertSame('e.id', $definition->idField());
        self::assertSame('s.id', $this->searchDefinition($this->registry, SearchKind::Service)->idField());

        $this->searchActAs(null, 'ann');
        self::assertNull($definition->seeAllUrl($query));
        self::assertSame('', $definition->toItem(['eid' => 1, 'wid' => 1, 'sid' => 1], $query)->subtitle);
        self::assertSame('', $definition->toItem(new RuntimeException(), $query)->title);
    }

    /**
     * The route and parameters of a tenant-level test URL, as SearchKit's recents store them.
     *
     * @return array{string, array<string, string>}
     */
    private function routeOf(string $url): array
    {
        $parameters = (new UrlMatcher(self::searchRoutes(), new RequestContext()))->match($url);
        $route      = (string) $parameters['_route'];
        unset($parameters['_route']);

        return [$route, array_map(static fn (mixed $value): string => (string) $value, $parameters)];
    }
}
