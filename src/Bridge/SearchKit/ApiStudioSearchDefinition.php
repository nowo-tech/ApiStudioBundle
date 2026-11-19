<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Bridge\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Event\ApiStudioSearchContextEvent;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Search\SearchAccess;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchHitMapper;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ctype_digit;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * One SearchKit group of Api Studio (1.18): workspaces or services by name, endpoints by name, path and HTTP method.
 * Registered once per {@see SearchKind} when SearchKit is installed and `search_kit.enabled`; SearchKit's
 * EntitySearchProvider runs the fixed flow (escaped `LIKE`, bounded candidates, the host's `ITEM_VISIBILITY` event,
 * the visible total).
 *
 * - **Tenant and scope** ({@see SearchContextResolver}): the studio's tenant resolver, the search scope id as the
 *   workspace scope (org level without one), then the host's {@see ApiStudioSearchContextEvent}.
 * - **Feature**: the same viewer as the signed-in user, then the studio's entry chain
 *   ({@see ApiStudioAccessSubscriber::admitsSearch()}: roles, access checker, `studio.open` with the scope checker in a
 *   scope, feature gate).
 * - **SQL**: tenant, host scope and visible ids of {@see ApiStudioAuthorization::scope()}.
 * - **Batch**: the tenant and scope re-checked byte-exact on every row (look-alike tenants never leak), then
 *   `workspace.view` and the folder checker ({@see SearchAccess}); one label call for the scope badges.
 * - **Items** ({@see SearchHitMapper}): names, protocol, method and path without its query string. Never a base URL,
 *   header, auth value, body, script or variable.
 *
 * Every hook resolves what it needs per call (worker-safe: nothing is kept in the service).
 */
final class ApiStudioSearchDefinition extends EntitySearchDefinition
{
    public function __construct(
        private readonly SearchKind $kind,
        private readonly SearchContextResolver $contexts,
        private readonly ApiStudioAuthorization $authorization,
        private readonly ?ApiStudioAccessSubscriber $entry,
        private readonly ApiWorkspaceRepository $workspaces,
        private readonly ApiServiceRepository $services,
        private readonly ApiEndpointRepository $endpoints,
        private readonly SearchAccess $access,
        private readonly SearchHitMapper $mapper,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function group(): string
    {
        return 'api_studio_' . $this->kind->value;
    }

    public function label(SearchQuery $query): string
    {
        $key = 'search.group.' . $this->kind->value;

        return $this->translator?->trans($key, [], ApiStudioBundle::TRANSLATION_DOMAIN, $query->locale) ?? $key;
    }

    public function icon(): string
    {
        return SearchHitMapper::ICONS[$this->kind->value];
    }

    public function subjectType(): string
    {
        return match ($this->kind) {
            SearchKind::Workspace => 'api_studio.workspace',
            SearchKind::Service   => 'api_studio.service',
            SearchKind::Endpoint  => 'api_studio.endpoint',
        };
    }

    public function subjectId(array|object $row): int
    {
        $id = is_array($row) ? ($row[$this->idKey()] ?? null) : null;

        return is_numeric($id) ? (int) $id : 0;
    }

    public function tenant(SearchQuery $query): ?string
    {
        $tenant = $this->context($query)?->tenant();

        return $tenant instanceof ApiStudioTenant ? SearchContextResolver::tenantKey($tenant) : null;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        $context = $this->context($query);
        $request = $this->contexts->request();
        if (!$context instanceof ApiStudioSearchContextEvent || !$request instanceof Request || !$this->entry instanceof ApiStudioAccessSubscriber || !$this->sameTenant($context, $tenant)) {
            return false;
        }

        return $this->contexts->run($context, function () use ($request, $query): bool { // @igor-ignore - run() calls it at once inside runAs() and keeps nothing
            $user = $this->authorization->user();

            // The search is the signed-in user's own: a query for another identifier is refused.
            return $user instanceof UserInterface && $user->getUserIdentifier() === $query->userIdentifier && $this->entry->admitsSearch($request);
        });
    }

    public function searchableFields(): array
    {
        return match ($this->kind) {
            SearchKind::Workspace => ['w.name'],
            SearchKind::Service   => ['s.name'],
            SearchKind::Endpoint  => ['e.name', 'e.path', 'e.method'],
        };
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $context = $this->context($query);
        if (!$context instanceof ApiStudioSearchContextEvent || !$this->sameTenant($context, $tenant)) {
            // Unreachable through the provider (tenant() and featureEnabled() refused first): a query of nothing.
            return $this->workspaces->createQueryBuilder('w')->select('w.id AS wid')->where('1 = 0');
        }

        return $this->contexts->run($context, function (): OrmQueryBuilder {
            $view = $this->authorization->scope();

            return match ($this->kind) {
                SearchKind::Workspace => $this->workspaces->searchQuery($view),
                SearchKind::Service   => $this->services->searchQuery($view),
                SearchKind::Endpoint  => $this->endpoints->searchQuery($view),
            };
        });
    }

    /**
     * The scope filter is already in {@see self::baseQuery()} (the context's workspace scope): a scope id the context
     * did not turn into a workspace scope is refused.
     */
    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return $this->contexts->scopes() && $this->context($query)?->workspaceScope() !== null;
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $context = $this->context($query);
        if (!$context instanceof ApiStudioSearchContextEvent || !$this->sameTenant($context, $tenant)) {
            return [];
        }

        return $this->contexts->run($context, function () use ($rows, $context, $query): array { // @igor-ignore - run() calls it at once inside runAs() and keeps nothing
            $view  = $this->authorization->scope();
            $exact = [];
            foreach ($rows as $row) {
                // Exact-key rule (audit UA-L1): the SQL filter's tenant and scope compared byte-exact.
                if (is_array($row) && ApiWorkspaceRepository::rowInScope($row, $view)) {
                    $row['wscope'] = (string) ($row['wscope'] ?? '');
                    $exact[]       = $row;
                }
            }
            $visible = $this->access->visible($this->kind, $exact);
            if ($context->workspaceScope() === null && $visible !== []) {
                $this->contexts->rememberLabels($this->kind, $query->userIdentifier, $query->scope, $this->mapper->labels($visible, $view->tenant));
            }

            return $visible;
        });
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $context = $this->context($query);
        $scope   = $this->contexts->scopes() ? $context?->workspaceScope() : null;
        $hit     = $this->mapper->hit(
            $this->kind,
            is_array($row) ? $row : [],
            $scope,
            $context?->routeParameters() ?? [],
            $this->contexts->rememberedLabels($this->kind, $query->userIdentifier, $query->scope),
        );

        return new SearchItem(
            $hit->title,
            $hit->url,
            $hit->subtitle,
            $hit->icon,
            $hit->scopeLabel !== null ? new SearchBadge($hit->scopeLabel) : null,
            null,
            null,
            $hit->updatedAt,
        );
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        $context = $this->context($query);

        return $this->mapper->moreUrl($this->kind, $this->contexts->scopes() ? $context?->workspaceScope() : null, $context?->routeParameters() ?? []);
    }

    public function idField(): string
    {
        return match ($this->kind) {
            SearchKind::Workspace => 'w.id',
            SearchKind::Service   => 's.id',
            SearchKind::Endpoint  => 'e.id',
        };
    }

    /**
     * Revalidated recents: the id of a show page of this group (tenant level or its `scope.path` twin).
     */
    public function referenceId(SearchReference $reference): ?int
    {
        $route = match ($this->kind) {
            SearchKind::Workspace => 'nowo_api_studio_workspace_show',
            SearchKind::Service   => 'nowo_api_studio_service_show',
            SearchKind::Endpoint  => 'nowo_api_studio_endpoint_show',
        };
        $id = $reference->routeParameters['id'] ?? null;
        if (ApiStudioRoutes::canonical($reference->route) !== $route) {
            return null;
        }
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        return is_string($id) && ctype_digit($id) && $id !== '0' && $id[0] !== '0' ? (int) $id : null;
    }

    private function context(SearchQuery $query): ?ApiStudioSearchContextEvent
    {
        return $this->contexts->resolve($this->kind, $query->userIdentifier, $query->scope);
    }

    private function sameTenant(ApiStudioSearchContextEvent $context, int|string $tenant): bool
    {
        $current = $context->tenant();
        $asked   = SearchContextResolver::tenantOf($tenant);

        return $current instanceof ApiStudioTenant && $asked instanceof ApiStudioTenant && $current->equals($asked);
    }

    private function idKey(): string
    {
        return match ($this->kind) {
            SearchKind::Workspace => 'wid',
            SearchKind::Service   => 'sid',
            SearchKind::Endpoint  => 'eid',
        };
    }
}
