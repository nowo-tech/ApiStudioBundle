<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;

use function array_map;
use function implode;
use function serialize;
use function sort;

/**
 * 1.18 SearchKit scenarios on the {@see SearchFixtures} data set, run on SQLite and on MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16: visibility in org and project scope, look-alike tenants and scopes, an access-check deny and a host
 * deny, folders, LIKE escaping, no secret in any result, and the query budget.
 */
trait SearchScenarios
{
    use SearchFixtures;

    private function assertOrgAndProjectVisibility(ScopeManagerRegistry $registry): void
    {
        // The scoped mount's links need the host's {project}; the tenant-level routes are used here.
        $workspaces = $this->searchProvider($registry, SearchKind::Workspace, scopedRoutes: false);

        // Org level (tenant_list: all, every scope visible): unscoped and scoped workspaces of org:acme only; the
        // look-alike tenant org:Acme and org:other never appear, whatever the collation of the name column.
        self::assertSame(['Payments', 'Payments look-alike scope', 'Payments project A', 'Payments project B'], $this->titles($this->searchFor($workspaces, 'payments')));

        // Visible scopes narrow it; `Project:a` is another scope than `project:a` (byte-exact).
        $this->searchScopes->visible = ['project:a'];
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        self::assertSame(['Payments', 'Payments project A'], $this->titles($this->searchFor($workspaces, 'payments')));
        $this->searchScopes->visible = null;

        // tenant_list: unscoped: only the tenant-level workspaces.
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        self::assertSame(['Payments'], $this->titles($this->searchFor($this->searchProvider($registry, SearchKind::Workspace, tenantList: 'unscoped'), 'payments')));

        // Project scope: only that scope's workspace, byte-exact.
        self::assertSame(['Payments project A'], $this->titles($this->searchFor($workspaces, 'payments', 10, 'project:a')));
        self::assertSame(['Payments look-alike scope'], $this->titles($this->searchFor($workspaces, 'payments', 10, 'Project:a')));
        self::assertNull($this->searchFor($workspaces, 'payments', 10, 'project:A'));
        self::assertSame(['Get ledger'], $this->titles($this->searchFor($this->searchProvider($registry, SearchKind::Endpoint, scopedRoutes: false), 'ledger', 10, 'project:b')));
    }

    private function assertTenantsNeverLeak(ScopeManagerRegistry $registry): void
    {
        $workspaces = $this->searchProvider($registry, SearchKind::Workspace);
        $this->searchActAs(new ApiStudioTenant('org', 'Acme'), 'ann');
        self::assertSame(['Payments look-alike'], $this->titles($this->searchFor($workspaces, 'payments')));
        $this->searchActAs(new ApiStudioTenant('org', 'other'), 'ann');
        self::assertSame(['Payments other'], $this->titles($this->searchFor($workspaces, 'payments')));
        $this->searchActAs(new ApiStudioTenant('org', 'ACME'), 'ann');
        self::assertNull($this->searchFor($workspaces, 'payments'));
        $this->searchActAs(new ApiStudioTenant('org', 'nobody'), 'ann');
        self::assertNull($this->searchFor($this->searchProvider($registry, SearchKind::Service), 'stripe'));
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
    }

    private function assertAccessDenyHidesRows(ScopeManagerRegistry $registry): void
    {
        $this->searchPermissions         = new SearchPermissionDouble();
        $this->searchPermissions->hidden = ['Payments', 'Payments project B'];
        $group                           = $this->searchFor($this->searchProvider($registry, SearchKind::Workspace), 'payments');
        self::assertSame(['Payments look-alike scope', 'Payments project A'], $this->titles($group));
        // The total counts only what the user may open.
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(2, $group->total);
        self::assertTrue($group->exactTotal);
        // Every row of a hidden workspace is hidden: its services and endpoints too (no count, no hint).
        self::assertNull($this->searchFor($this->searchProvider($registry, SearchKind::Service), 'stripe'));
        self::assertNull($this->searchFor($this->searchProvider($registry, SearchKind::Endpoint), 'charge'));
        self::assertSame(['Get ledger'], $this->titles($this->searchFor($this->searchProvider($registry, SearchKind::Endpoint), 'ledger')));

        // The host's SearchKit visibility event (one batch, deny wins) hides rows the same way.
        $this->searchPermissions = new SearchPermissionDouble();
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $subjects = [];
        $this->searchEvents->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event) use (&$subjects): void {
            foreach ($event->subjects() as $subject) {
                $subjects[] = $subject->type;
                $event->deny($subject);
            }
        });
        self::assertNull($this->searchFor($this->searchProvider($registry, SearchKind::Workspace), 'payments'));
        self::assertSame(['api_studio.workspace', 'api_studio.workspace', 'api_studio.workspace', 'api_studio.workspace'], $subjects);
        $this->searchEvents->removeListener(SearchKitEvents::ITEM_VISIBILITY, $this->searchEvents->getListeners(SearchKitEvents::ITEM_VISIBILITY)[0]);
    }

    private function assertFoldersNarrowEndpoints(ScopeManagerRegistry $registry): void
    {
        $this->searchFolders = new SearchFolderDouble();
        self::assertSame(['Charge stats', 'Create charge', 'List charges', 'Refund charge'], $this->titles($this->searchFor($this->searchProvider($registry, SearchKind::Endpoint), 'charge')));
        $this->searchFolders->hidden = [$this->searchIds['folder:Private']];
        $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
        $group = $this->searchFor($this->searchProvider($registry, SearchKind::Endpoint), 'charge');
        self::assertSame(['Charge stats', 'Create charge', 'List charges'], $this->titles($group));
        self::assertSame(3, $group?->total);
    }

    private function assertLikeIsEscaped(ScopeManagerRegistry $registry): void
    {
        $workspaces = $this->searchProvider($registry, SearchKind::Workspace);
        self::assertSame(['Promo 100% off'], $this->titles($this->searchFor($workspaces, '100%')));
        self::assertSame(['Promo 100% off'], $this->titles($this->searchFor($workspaces, '%')));
        self::assertSame(['rate_limit'], $this->titles($this->searchFor($workspaces, 'rate_limit')));
        self::assertSame(['rate_limit'], $this->titles($this->searchFor($workspaces, '_')));
        self::assertNull($this->searchFor($workspaces, '!'));
        // Case-insensitive, every word must match.
        self::assertSame(['Promo 1000 off'], $this->titles($this->searchFor($workspaces, 'PROMO 1000')));
    }

    private function assertEndpointsByNamePathAndMethod(ScopeManagerRegistry $registry): void
    {
        $endpoints = $this->searchProvider($registry, SearchKind::Endpoint);
        self::assertSame(['Create charge', 'Refund charge'], $this->titles($this->searchFor($endpoints, 'post')));
        self::assertSame(['Refund charge'], $this->titles($this->searchFor($endpoints, 'POST refunds')));
        self::assertSame(['Charge stats'], $this->titles($this->searchFor($endpoints, '/v1/stats')));
        self::assertSame(['Stripe gateway'], $this->titles($this->searchFor($this->searchProvider($registry, SearchKind::Service), 'stripe')));

        $group = $this->searchFor($endpoints, 'create');
        self::assertInstanceOf(SearchGroup::class, $group);
        $item = $group->items[0];
        self::assertSame('POST /v1/charges · Stripe gateway', $item->subtitle);
        self::assertStringStartsWith('/studio/workspaces/' . $this->searchIds['Payments'] . '/services/' . $this->searchIds['service:Stripe gateway'] . '/endpoints/', $item->url);
        self::assertSame('tabler:route', $item->icon);
        self::assertNull($group->moreUrl);

        $this->assertNoSecret($this->searchFor($this->searchProvider($registry, SearchKind::Workspace), 'payments'));
        $this->assertNoSecret($this->searchFor($this->searchProvider($registry, SearchKind::Service), 'stripe'));
        $this->assertNoSecret($this->searchFor($endpoints, 'charge'));
        // A query string never reaches the search either (only the path's own text is searched … and shown cut).
        self::assertNull($this->searchFor($endpoints, 'SECRET_BODY'));
    }

    /**
     * Workspaces and services: 2 statements (the rows, the workspaces to check); endpoints: 3 with a folder checker
     * and candidates in folders (their services' folder rows). Independent of the number of rows (no N+1).
     */
    private function assertQueryBudget(ScopeManagerRegistry $registry): void
    {
        $cases = [
            [SearchKind::Workspace, 'payments', 2],
            [SearchKind::Service, 'ledger', 2],
            [SearchKind::Endpoint, 'charge', 3],
            [SearchKind::Endpoint, 'ledger', 2],
            [SearchKind::Workspace, 'nothing-like-this', 1],
        ];
        foreach ($cases as [$kind, $text, $budget]) {
            $this->searchActAs(new ApiStudioTenant('org', 'acme'), 'ann');
            $provider = $this->searchProvider($registry, $kind);
            $this->searchCounter->reset();
            $this->searchFor($provider, $text);
            self::assertSame($budget, $this->searchCounter->count(), $kind->value . ' "' . $text . '": ' . implode("\n", $this->searchCounter->queries()));
        }
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        $titles = array_map(static fn (SearchItem $item): string => $item->title, $group->items ?? []);
        sort($titles);

        return $titles;
    }

    private function assertNoSecret(?SearchGroup $group): void
    {
        self::assertInstanceOf(SearchGroup::class, $group);
        $text = serialize($group);
        foreach (['SECRET_BASE', 'SECRET_TOKEN', 'SECRET_HEADER', 'SECRET_SCRIPT', 'SECRET_BODY', 'SECRET_QS', 'SECRET_DESC'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
