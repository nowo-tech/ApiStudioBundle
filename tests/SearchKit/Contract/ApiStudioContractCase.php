<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Contract;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tests\SearchKit\SkipsWithoutSearchKit;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\SearchFixtures;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\QueryCounter;

use function bin2hex;
use function random_bytes;

/**
 * SearchKit's provider contract (1.9 test kit) for one Api Studio group on one database: tenants `org:acme` and
 * `org:other` (tenancy, scopes and folders on), the studio's checks with test doubles, a new request per viewer.
 */
abstract class ApiStudioContractCase extends SearchKitContractBase
{
    use SearchFixtures;
    use SkipsWithoutSearchKit;

    private ScopeManagerRegistry $registry;

    abstract protected static function kind(): SearchKind;

    /**
     * DBAL parameters of the database (skip the test when it is not available).
     *
     * @return array<string, mixed>
     */
    abstract protected function connection(): array;

    /** @var array<int, array{int, int}> row id => [workspace id, service id] */
    private array $seeded = [];

    protected function setUp(): void
    {
        $this->skipWithoutSearchKit();
        $this->registry = $this->searchRegistry($this->connection());
        // Rows of `project:archived` are filtered in SQL (seedArchived()).
        $this->searchScopes->visible = ['project:visible'];
    }

    protected function tearDown(): void
    {
        // Not set when connection() skipped the test (no database URL).
        if (isset($this->registry)) {
            $this->dropSearchSchema($this->registry);
        }
    }

    protected function definition(): EntitySearchDefinition
    {
        return $this->searchDefinition($this->registry, static::kind());
    }

    protected function queryCounter(): QueryCounter
    {
        return $this->searchCounter;
    }

    protected function tenants(): array
    {
        return ['org:acme', 'org:other'];
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->seedRow($tenant, $text, null);
    }

    /**
     * A row whose workspace the permission checker hides (`workspace.view` denied): SQL keeps it, the batch drops it.
     */
    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        $workspace                         = 'Hidden ' . bin2hex(random_bytes(6));
        $this->searchPermissions->hidden[] = static::kind() === SearchKind::Workspace ? $text : $workspace;

        return $this->seedRow($tenant, $text, null, $workspace);
    }

    /**
     * Api Studio has no archive state; the SQL-filtered equivalent is a row of a scope the viewer may not list
     * (`visibleScopes()` leaves it out, so the base query never returns it).
     */
    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        return $this->seedRow($tenant, $text, 'project:archived');
    }

    protected function reference(int|string $id): SearchReference
    {
        [$workspace, $service] = $this->seeded[$id] ?? [0, 0];

        return match (static::kind()) {
            SearchKind::Workspace => new SearchReference('/studio/workspaces/' . $id, 'api_studio_workspaces', 'nowo_api_studio_workspace_show', ['id' => (string) $id]),
            SearchKind::Service   => new SearchReference('/studio/workspaces/' . $workspace . '/services/' . $id, 'api_studio_services', 'nowo_api_studio_service_show', ['workspaceId' => (string) $workspace, 'id' => (string) $id]),
            SearchKind::Endpoint  => new SearchReference('/studio/workspaces/' . $workspace . '/services/' . $service . '/endpoints/' . $id, 'api_studio_endpoints', 'nowo_api_studio_endpoint_show', ['workspaceId' => (string) $workspace, 'serviceId' => (string) $service, 'id' => (string) $id]),
        };
    }

    /**
     * One row of $kind whose searchable text is $text; returns its id.
     */
    private function seedRow(int|string $tenant, string $text, ?string $scope, string $workspaceName = 'Workspace'): int
    {
        $em    = $this->searchEm($this->registry);
        $owner = SearchContextResolver::tenantOf($tenant);
        self::assertNotNull($owner);
        $kind      = static::kind();
        $workspace = $this->searchWorkspace($em, $kind === SearchKind::Workspace ? $text : $workspaceName, $owner->id, $scope);
        $service   = null;
        $endpoint  = null;
        if ($kind !== SearchKind::Workspace) {
            $service = new ApiService($kind === SearchKind::Service ? $text : 'Service', 's-' . bin2hex(random_bytes(6)));
            $workspace->addService($service);
            $em->persist($service);
            if ($kind === SearchKind::Endpoint) {
                $endpoint = (new ApiEndpoint($text, 'e-' . bin2hex(random_bytes(6))))->setPath('/x');
                $service->addEndpoint($endpoint);
            }
        }
        $em->flush();
        $id                = (int) ($endpoint?->getId() ?? $service?->getId() ?? $workspace->getId());
        $this->seeded[$id] = [(int) $workspace->getId(), (int) $service?->getId()];
        $em->clear();

        return $id;
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->searchActAs($tenant === null ? null : SearchContextResolver::tenantOf($tenant), $userIdentifier);
    }

    protected function disableFeature(): void
    {
        $this->searchGate->enabled = false;
    }
}
