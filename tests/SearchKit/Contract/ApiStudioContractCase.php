<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit\Contract;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tests\Support\ScopeManagerRegistry;
use Nowo\ApiStudioBundle\Tests\Support\SearchFixtures;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;

use function bin2hex;
use function random_bytes;

/**
 * SearchKit's provider contract (1.9 test kit) for one Api Studio group on one database: tenants `org:acme` and
 * `org:other` (tenancy, scopes and folders on), the studio's checks with test doubles, a new request per viewer.
 */
abstract class ApiStudioContractCase extends EntitySearchProviderTestCase
{
    use SearchFixtures;

    private ScopeManagerRegistry $registry;

    abstract protected static function kind(): SearchKind;

    /**
     * DBAL parameters of the database (skip the test when it is not available).
     *
     * @return array<string, mixed>
     */
    abstract protected function connection(): array;

    protected function setUp(): void
    {
        $this->registry = $this->searchRegistry($this->connection());
    }

    protected function tearDown(): void
    {
        $this->dropSearchSchema($this->registry);
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

    protected function seed(int|string $tenant, string $text): void
    {
        $em    = $this->searchEm($this->registry);
        $owner = SearchContextResolver::tenantOf($tenant);
        self::assertNotNull($owner);
        $kind      = static::kind();
        $workspace = $this->searchWorkspace($em, $kind === SearchKind::Workspace ? $text : 'Workspace', $owner->id, null);
        if ($kind !== SearchKind::Workspace) {
            $service = new ApiService($kind === SearchKind::Service ? $text : 'Service', 's-' . bin2hex(random_bytes(6)));
            $workspace->addService($service);
            $em->persist($service);
            if ($kind === SearchKind::Endpoint) {
                $service->addEndpoint((new ApiEndpoint($text, 'e-' . bin2hex(random_bytes(6))))->setPath('/x'));
            }
        }
        $em->flush();
        $em->clear();
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
