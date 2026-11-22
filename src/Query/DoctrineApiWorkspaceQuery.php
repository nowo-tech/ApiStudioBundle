<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\ApiStudioBundle\Doctrine\ApiStudioEntityManagerResolver;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function array_flip;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function mb_strtolower;
use function strtr;

/**
 * 1.19 (DDD-host checklist B7): {@see ApiWorkspaceQueryInterface} on the bundle's entity manager. Scalar DQL only (no
 * entity is loaded or tracked); the manager is resolved at every call, nothing is kept (worker mode).
 *
 * @internal Hosts depend on the interface
 */
final readonly class DoctrineApiWorkspaceQuery implements ApiWorkspaceQueryInterface
{
    /** `LIKE … ESCAPE '!'` (a backslash means different things on MySQL and PostgreSQL). */
    private const LIKE_ESCAPES = ['!' => '!!', '%' => '!%', '_' => '!_'];

    public function __construct(
        private ApiStudioEntityManagerResolver $managers,
    ) {
    }

    /**
     * LIMIT / OFFSET run in SQL, before the exact PHP re-check of tenant and scope: on a folding collation, look-alike
     * rows take room in the page and are then dropped, so a page can be shorter than the limit (never wrong, never
     * another tenant's row). The total is exact.
     */
    public function find(ApiWorkspaceCriteria $criteria): ApiWorkspacePage
    {
        $manager            = $this->managers->entityManager();
        [$tenancy, $scopes] = self::columns($manager);
        if (!self::satisfiable($criteria, $tenancy, $scopes)) {
            return new ApiWorkspacePage([], 0, $criteria->page, $criteria->limit);
        }

        $query = $manager->createQueryBuilder()
            ->select('w.id AS wid', 'w.name AS wname', 'w.slug AS wslug', 'w.description AS wdescription', 'w.enabled AS wenabled', 'w.createdAt AS wcreated', 'w.updatedAt AS wupdated')
            ->from(ApiWorkspace::class, 'w')
            ->setFirstResult($criteria->offset())
            ->setMaxResults($criteria->limit);
        $direction = $criteria->descending ? 'DESC' : 'ASC';
        $field     = match ($criteria->sort) {
            ApiWorkspaceSort::Name      => 'w.name',
            ApiWorkspaceSort::CreatedAt => 'w.createdAt',
            ApiWorkspaceSort::UpdatedAt => 'w.updatedAt',
            ApiWorkspaceSort::Id        => null,
        };
        if ($field !== null) {
            $query->addOrderBy($field, $direction);
        }
        $query->addOrderBy('w.id', $direction);
        self::narrow($query, $criteria, $tenancy, $scopes);

        $items = [];
        $idSet = $criteria->ids === null ? null : array_flip($criteria->ids);
        foreach (self::rows($query) as $row) {
            if (self::exact($row, $criteria, $tenancy, $scopes, $idSet)) {
                $items[] = self::view($row, $criteria->tenant, $scopes);
            }
        }

        return new ApiWorkspacePage($items, $this->count($criteria), $criteria->page, $criteria->limit);
    }

    public function count(ApiWorkspaceCriteria $criteria): int
    {
        $manager            = $this->managers->entityManager();
        [$tenancy, $scopes] = self::columns($manager);
        if (!self::satisfiable($criteria, $tenancy, $scopes)) {
            return 0;
        }

        $query = $manager->createQueryBuilder()->from(ApiWorkspace::class, 'w');
        if (!$tenancy && !$scopes) {
            // No string key to re-check: ids are integers, compared exactly by the server.
            self::narrow($query->select('COUNT(w.id)'), $criteria, false, false);

            return (int) $query->getQuery()->getSingleScalarResult();
        }

        // Exact keys: every row's tenant and scope are compared again in PHP (one short row per workspace; a GROUP BY
        // could merge a look-alike key into an exact one on a padding collation).
        self::narrow($query->select('w.id AS wid'), $criteria, $tenancy, $scopes);
        $count = 0;
        $idSet = $criteria->ids === null ? null : array_flip($criteria->ids);
        foreach (self::rows($query) as $row) {
            if (self::exact($row, $criteria, $tenancy, $scopes, $idSet)) {
                ++$count;
            }
        }

        return $count;
    }

    public function get(ApiStudioTenant $tenant, int $workspaceId): ?ApiWorkspaceView
    {
        if ($workspaceId < 1) {
            return null;
        }

        $page = $this->find(ApiWorkspaceCriteria::forTenant($tenant)->withIds([$workspaceId])->onPage(1, 1));
        $view = $page->items[0] ?? null;

        return $view instanceof ApiWorkspaceView && $view->id === $workspaceId ? $view : null;
    }

    public function workspaceIdOfService(ApiStudioTenant $tenant, int $serviceId): ?int
    {
        if ($serviceId < 1) {
            return null;
        }

        $manager = $this->managers->entityManager();
        $query   = $manager->createQueryBuilder()
            ->select('s.id AS rid', 'w.id AS wid')
            ->from(ApiService::class, 's')
            ->join('s.workspace', 'w')
            ->where('s.id = :nowo_api_studio_id')
            ->setParameter('nowo_api_studio_id', $serviceId);

        return $this->ownerWorkspace($manager, $query, $tenant, $serviceId);
    }

    public function workspaceIdOfEndpoint(ApiStudioTenant $tenant, int $endpointId): ?int
    {
        if ($endpointId < 1) {
            return null;
        }

        $manager = $this->managers->entityManager();
        $query   = $manager->createQueryBuilder()
            ->select('e.id AS rid', 'w.id AS wid')
            ->from(ApiEndpoint::class, 'e')
            ->join('e.service', 's')
            ->join('s.workspace', 'w')
            ->where('e.id = :nowo_api_studio_id')
            ->setParameter('nowo_api_studio_id', $endpointId);

        return $this->ownerWorkspace($manager, $query, $tenant, $endpointId);
    }

    /**
     * The workspace id of the single row of $query (resource `rid`, workspace `wid`) when the resource id is $id and
     * the workspace belongs to $tenant, compared exactly in SQL and again in PHP.
     */
    private function ownerWorkspace(EntityManagerInterface $manager, QueryBuilder $query, ApiStudioTenant $tenant, int $id): ?int
    {
        [$tenancy] = self::columns($manager);
        $criteria  = ApiWorkspaceCriteria::forTenant($tenant);
        if (!self::satisfiable($criteria, $tenancy, false)) {
            return null;
        }
        self::narrow($query, $criteria, $tenancy, false);
        $query->setMaxResults(1);

        foreach (self::rows($query) as $row) {
            if (self::integer($row['rid'] ?? null) === $id && self::exact($row, $criteria, $tenancy, false)) {
                return self::integer($row['wid'] ?? null);
            }
        }

        return null;
    }

    /**
     * Whether the tenant and scope columns are mapped (`tenancy.enabled`, `scope.enabled`).
     *
     * @return array{bool, bool}
     */
    private static function columns(EntityManagerInterface $manager): array
    {
        $metadata = $manager->getClassMetadata(ApiWorkspace::class);

        return [$metadata->hasField('tenantType'), $metadata->hasField('hostScope')];
    }

    /**
     * False when nothing can match: another tenant than the instance one without tenancy, a scope without scopes, an
     * empty id list.
     */
    private static function satisfiable(ApiWorkspaceCriteria $criteria, bool $tenancy, bool $scopes): bool
    {
        if (!$tenancy && !$criteria->tenant->isInstance()) {
            return false;
        }

        if (!$scopes && $criteria->scopeKey !== null && $criteria->scopeKey !== '') {
            return false;
        }

        return $criteria->ids !== [];
    }

    /**
     * The SQL part of the filters (bound parameters only), plus the tenant and scope columns the PHP re-check reads.
     */
    private static function narrow(QueryBuilder $query, ApiWorkspaceCriteria $criteria, bool $tenancy, bool $scopes): void
    {
        if ($tenancy) {
            $query->andWhere('w.tenantType = :nowo_api_studio_tenant_type AND w.tenantId = :nowo_api_studio_tenant_id')
                ->setParameter('nowo_api_studio_tenant_type', $criteria->tenant->type)
                ->setParameter('nowo_api_studio_tenant_id', $criteria->tenant->id)
                ->addSelect('w.tenantType AS wtenanttype', 'w.tenantId AS wtenantid');
        }

        if ($scopes) {
            $query->addSelect('w.hostScope AS wscope');
            if ($criteria->scopeKey !== null) {
                $query->andWhere('w.hostScope = :nowo_api_studio_scope')->setParameter('nowo_api_studio_scope', $criteria->scopeKey);
            }
        }

        if ($criteria->ids !== null) {
            $query->andWhere('w.id IN (:nowo_api_studio_ids)')->setParameter('nowo_api_studio_ids', $criteria->ids, ArrayParameterType::INTEGER);
        }

        if ($criteria->enabled !== null) {
            $query->andWhere('w.enabled = :nowo_api_studio_enabled')->setParameter('nowo_api_studio_enabled', $criteria->enabled);
        }

        if ($criteria->text !== null) {
            $query->andWhere("(LOWER(w.name) LIKE :nowo_api_studio_text ESCAPE '!' OR LOWER(w.slug) LIKE :nowo_api_studio_text ESCAPE '!')")
                ->setParameter('nowo_api_studio_text', '%' . strtr(mb_strtolower($criteria->text), self::LIKE_ESCAPES) . '%');
        }
    }

    /**
     * Exact-key rule (house LQ rule): the row's id, tenant and scope compared again with `===` after the SQL filter.
     *
     * @param array<string, mixed> $row
     * @param array<int, int>|null $idSet the criteria's ids as keys (null: no id filter)
     */
    private static function exact(array $row, ApiWorkspaceCriteria $criteria, bool $tenancy, bool $scopes, ?array $idSet = null): bool
    {
        $id = self::integer($row['wid'] ?? null);
        if ($id === null || ($idSet !== null && !isset($idSet[$id]))) {
            return false;
        }

        if ($tenancy && (self::text($row['wtenanttype'] ?? null) !== $criteria->tenant->type || self::text($row['wtenantid'] ?? null) !== $criteria->tenant->id)) {
            return false;
        }

        return !$scopes || $criteria->scopeKey === null || self::text($row['wscope'] ?? null) === $criteria->scopeKey;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function view(array $row, ApiStudioTenant $tenant, bool $scopes): ApiWorkspaceView
    {
        $scope       = $scopes ? self::text($row['wscope'] ?? null) : '';
        $description = $row['wdescription'] ?? null;

        return new ApiWorkspaceView(
            (int) self::integer($row['wid'] ?? null),
            (string) self::text($row['wname'] ?? null),
            (string) self::text($row['wslug'] ?? null),
            is_scalar($description) ? (string) $description : null,
            is_bool($row['wenabled'] ?? null) ? $row['wenabled'] : (bool) self::integer($row['wenabled'] ?? null),
            $tenant,
            $scope === '' || $scope === null ? null : $scope,
            self::date($row['wcreated'] ?? null),
            self::date($row['wupdated'] ?? null),
        );
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private static function rows(QueryBuilder $query): iterable
    {
        foreach ($query->getQuery()->toIterable([], AbstractQuery::HYDRATE_ARRAY) as $row) {
            if (is_array($row)) {
                /* @var array<string, mixed> $row */
                yield $row;
            }
        }
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_scalar($value) && (string) (int) $value === (string) $value ? (int) $value : null);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        // `datetime_immutable` columns hydrate as DateTimeImmutable; anything else is not a date of this table.
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable('@0');
    }
}
