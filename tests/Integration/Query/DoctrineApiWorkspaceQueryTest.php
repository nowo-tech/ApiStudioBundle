<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Query;

use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceCriteria;
use Nowo\ApiStudioBundle\Query\DoctrineApiWorkspaceQuery;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use Nowo\ApiStudioBundle\Tests\Support\WorkspaceQueryScenario;
use PHPUnit\Framework\TestCase;

/**
 * 1.19 (DDD-host checklist B7): the workspace query port on SQLite — with tenancy and scopes (the shared scenario, also
 * run on real servers in tests/Database) and on a 1.10 schema (no tenant or scope column).
 */
final class DoctrineApiWorkspaceQueryTest extends TestCase
{
    use InMemoryOrmTrait;
    use ScopeOrmTrait;
    use WorkspaceQueryScenario;

    public function testTenancyAndScopes(): void
    {
        $registry = $this->sqliteScopeRegistry();
        $em       = $this->scopeEntityManager($registry);
        $ids      = $this->seedWorkspaceQueryScenario($em);

        $this->assertWorkspaceQueryScenario($em, $registry, $ids);
    }

    public function testWithoutTenancyOrScopesEverythingIsTheInstanceTenantAtTenantLevel(): void
    {
        $em        = $this->createEntityManager();
        $workspace = new ApiWorkspace('Payments', 'payments');
        $service   = new ApiService('Cards', 'cards');
        $endpoint  = new ApiEndpoint('Charge', 'charge');
        $service->addEndpoint($endpoint);
        $workspace->addService($service);
        $em->persist($workspace);
        $em->persist(new ApiWorkspace('Billing', 'billing'));
        $em->flush();
        $em->clear();

        $query    = new DoctrineApiWorkspaceQuery(new ApiStudioEntityManagerResolver($this->createRegistry($em)));
        $instance = ApiWorkspaceCriteria::forTenant(ApiStudioTenant::instance());
        $acme     = new ApiStudioTenant('org', 'acme');

        self::assertSame(2, $query->find($instance)->total);
        self::assertSame(2, $query->count($instance));
        self::assertSame(2, $query->count($instance->inScope(null)));
        self::assertSame(1, $query->count($instance->withIds([(int) $workspace->getId()])));
        self::assertSame(0, $query->count($instance->inScope(new WorkspaceScope('project:1'))), 'no scope without scope.enabled');
        self::assertSame([], $query->find($instance->inScope(new WorkspaceScope('project:1')))->items);
        self::assertSame(0, $query->count(ApiWorkspaceCriteria::forTenant($acme)), 'no tenant but the instance one without tenancy');
        self::assertNull($query->get($acme, (int) $workspace->getId()));
        self::assertNull($query->workspaceIdOfService($acme, (int) $service->getId()));

        $view = $query->get(ApiStudioTenant::instance(), (int) $workspace->getId());
        self::assertNotNull($view);
        self::assertTrue($view->tenant->isInstance());
        self::assertNull($view->scope);
        self::assertSame($workspace->getId(), $query->workspaceIdOfService(ApiStudioTenant::instance(), (int) $service->getId()));
        self::assertSame($workspace->getId(), $query->workspaceIdOfEndpoint(ApiStudioTenant::instance(), (int) $endpoint->getId()));
        self::assertSame(0, $em->getUnitOfWork()->size());
    }
}
