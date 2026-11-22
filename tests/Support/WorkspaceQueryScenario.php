<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceCriteria;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceSort;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceView;
use Nowo\ApiStudioBundle\Query\DoctrineApiWorkspaceQuery;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function array_map;

/**
 * 1.19 (DDD-host checklist B7): one scenario of the workspace query port with tenancy and scopes on, run on SQLite
 * (Integration) and on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16 (Database): exact tenant and scope keys against
 * look-alikes stored next to them (other case; padded, written with SQL as the tenant value object refuses it),
 * literal text search, paging and the service / endpoint lookups. No entity stays managed by a read.
 */
trait WorkspaceQueryScenario
{
    /**
     * @return array<string, int> name => id (workspaces w*, services s*, endpoints e*)
     */
    private function seedWorkspaceQueryScenario(EntityManagerInterface $em): array
    {
        $acme = new ApiStudioTenant('org', 'acme');
        $rows = [
            'payments' => ['Payments', 'payments', $acme, null, true],
            'billing'  => ['Billing', 'billing', $acme, 'project:1', true],
            'archive'  => ['Archive', 'old_archive', $acme, 'project:2', false],
            'shout'    => ['Payments shout', 'payments-shout', new ApiStudioTenant('org', 'ACME'), 'Project:1', true],
            'globex'   => ['Globex', 'globex', new ApiStudioTenant('org', 'globex'), null, true],
            'padded'   => ['Padded', 'padded', new ApiStudioTenant('org', 'acmf'), null, true],
        ];
        /** @var array<string, ApiWorkspace> $entities */
        $entities = [];
        foreach ($rows as $key => [$name, $slug, $tenant, $scope, $enabled]) {
            $workspace = (new ApiWorkspace($name, $slug))->assignTenant($tenant)->assignScope(WorkspaceScope::fromStored((string) $scope))->setEnabled($enabled);
            $em->persist($workspace);
            $entities[$key] = $workspace;
        }
        $charge  = new ApiEndpoint('Charge', 'charge');
        $service = (new ApiService('Cards', 'cards'))->addEndpoint($charge);
        $leak    = new ApiEndpoint('Leak', 'leak');
        $foreign = (new ApiService('Foreign', 'foreign'))->addEndpoint($leak);
        $em->persist($service->setWorkspace($entities['payments'] ?? null));
        $em->persist($foreign->setWorkspace($entities['globex'] ?? null));
        $em->flush();

        $ids = ['s1' => (int) $service->getId(), 'e1' => (int) $charge->getId(), 's5' => (int) $foreign->getId(), 'e5' => (int) $leak->getId()];
        foreach ($entities as $key => $workspace) {
            $ids[$key] = (int) $workspace->getId();
        }

        // Look-alikes the value objects refuse, written with SQL: a padded tenant id and a padded scope.
        $em->getConnection()->executeStatement("UPDATE api_studio_workspace SET tenant_id = 'acme ' WHERE id = ?", [$ids['padded']]);
        $em->getConnection()->executeStatement("UPDATE api_studio_workspace SET host_scope = 'project:1 ' WHERE id = ?", [$ids['archive']]);
        $em->clear();

        return $ids;
    }

    /**
     * @param array<string, int> $ids
     */
    private function assertWorkspaceQueryScenario(EntityManagerInterface $em, ManagerRegistry $registry, array $ids): void
    {
        $query = new DoctrineApiWorkspaceQuery(new ApiStudioEntityManagerResolver($registry));
        $acme  = ApiWorkspaceCriteria::forTenant(new ApiStudioTenant('org', 'acme'));

        // Exact tenant: neither `ACME` nor `acme ` (padded) is acme.
        $page = $query->find($acme);
        self::assertSame(['Archive', 'Billing', 'Payments'], self::names($page->items));
        self::assertSame(3, $page->total);
        self::assertSame(3, $query->count($acme));
        $payments = $page->items[2];
        self::assertSame($ids['payments'], $payments->id);
        self::assertSame('payments', $payments->slug);
        self::assertNull($payments->scope);
        self::assertTrue($payments->enabled);
        self::assertSame('org', $payments->tenant->type);
        self::assertSame('project:1', $page->items[1]->scope);

        // Exact scope: `Project:1` and `project:1 ` (padded) are not project:1.
        self::assertSame(['Billing'], self::names($query->find($acme->inScope(new WorkspaceScope('project:1')))->items));
        self::assertSame(1, $query->count($acme->inScope(new WorkspaceScope('project:1'))));
        self::assertSame(['Payments'], self::names($query->find($acme->inScope(null))->items));
        self::assertSame([], $query->find($acme->inScope(new WorkspaceScope('project:2')))->items, 'the archive scope was padded');
        self::assertSame(['Payments shout'], self::names($query->find(ApiWorkspaceCriteria::forTenant(new ApiStudioTenant('org', 'ACME'))->inScope(new WorkspaceScope('Project:1')))->items));

        // Ids narrow within the tenant only.
        self::assertSame(['Payments'], self::names($query->find($acme->withIds([$ids['payments'], $ids['globex'], $ids['shout']]))->items));
        self::assertSame(1, $query->count($acme->withIds([$ids['payments'], $ids['globex']])));
        self::assertSame(0, $query->count($acme->withIds([])));
        self::assertSame([], $query->find($acme->withIds([]))->items);

        // Literal text (case-insensitive), on the name or the slug.
        self::assertSame(['Payments'], self::names($query->find($acme->matching('PAY'))->items));
        self::assertSame(['Archive'], self::names($query->find($acme->matching('d_a'))->items));
        self::assertSame([], $query->find($acme->matching('d%a'))->items);
        self::assertSame([], $query->find($acme->matching('_x'))->items);
        self::assertSame([], $query->find($acme->matching('!'))->items);

        self::assertSame(['Archive'], self::names($query->find($acme->withEnabled(false))->items));
        self::assertSame(['Archive', 'Billing', 'Payments'], self::names($query->find($acme->sortedBy(ApiWorkspaceSort::Id, true))->items));
        self::assertSame(['Payments', 'Billing', 'Archive'], self::names($query->find($acme->sortedBy(ApiWorkspaceSort::Name, true))->items));
        self::assertCount(3, $query->find($acme->sortedBy(ApiWorkspaceSort::CreatedAt))->items);
        self::assertCount(3, $query->find($acme->sortedBy(ApiWorkspaceSort::UpdatedAt, true))->items);

        $second = $query->find($acme->onPage(2, 2));
        self::assertSame(['Payments'], self::names($second->items));
        self::assertSame(3, $second->total);
        self::assertSame(2, $second->pages());

        // One workspace by id: exact tenant, any scope.
        self::assertInstanceOf(ApiWorkspaceView::class, $query->get(new ApiStudioTenant('org', 'acme'), $ids['billing']));
        self::assertNull($query->get(new ApiStudioTenant('org', 'acme'), $ids['shout']));
        self::assertNull($query->get(new ApiStudioTenant('org', 'acme'), $ids['padded']));
        self::assertNull($query->get(new ApiStudioTenant('org', 'acme'), $ids['globex']));
        self::assertNull($query->get(new ApiStudioTenant('org', 'acme'), 999_999));
        self::assertNull($query->get(new ApiStudioTenant('org', 'acme'), 0));
        self::assertNull($query->get(ApiStudioTenant::instance(), $ids['payments']));

        // Service and endpoint lookups: the owning workspace in that tenant, else null.
        self::assertSame($ids['payments'], $query->workspaceIdOfService(new ApiStudioTenant('org', 'acme'), $ids['s1']));
        self::assertSame($ids['payments'], $query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'acme'), $ids['e1']));
        self::assertNull($query->workspaceIdOfService(new ApiStudioTenant('org', 'acme'), $ids['s5']));
        self::assertNull($query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'acme'), $ids['e5']));
        self::assertNull($query->workspaceIdOfService(new ApiStudioTenant('org', 'ACME'), $ids['s1']));
        self::assertNull($query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'globex'), $ids['e1']));
        self::assertSame($ids['globex'], $query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'globex'), $ids['e5']));
        self::assertNull($query->workspaceIdOfService(new ApiStudioTenant('org', 'acme'), 0));
        self::assertNull($query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'acme'), -1));
        self::assertNull($query->workspaceIdOfEndpoint(new ApiStudioTenant('org', 'acme'), 999_999));

        // Scalar reads only: nothing is loaded into the bundle's manager.
        self::assertSame(0, $em->getUnitOfWork()->size());
    }

    /**
     * @param list<ApiWorkspaceView> $views
     *
     * @return list<string>
     */
    private static function names(array $views): array
    {
        return array_map(static fn (ApiWorkspaceView $view): string => $view->name, $views);
    }
}
