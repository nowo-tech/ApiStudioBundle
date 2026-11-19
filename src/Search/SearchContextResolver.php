<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Search;

use Nowo\ApiStudioBundle\Event\ApiStudioSearchContextEvent;
use Nowo\ApiStudioBundle\Scope\ScopeContext;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function explode;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;

/**
 * Where a global search runs (1.18): the tenant (`tenancy.resolver` / `provider`), the workspace scope of the search
 * scope id, then the host's {@see ApiStudioSearchContextEvent}. Resolved once per group, viewer and scope id of the main
 * request and memoised on the request itself (the provider asks several times per search; worker-safe: nothing is kept
 * in the service). Without a main request nothing is searchable.
 *
 * {@see self::run()} runs a callable inside that tenant and scope ({@see ApiStudioTenantContext::runAs()},
 * {@see ScopeContext::runAs()}), so the bundle's authorization service decides as on a studio route.
 */
final readonly class SearchContextResolver
{
    public const REQUEST_ATTRIBUTE = '_nowo_api_studio_search_context';

    public const LABELS_ATTRIBUTE = '_nowo_api_studio_search_labels';

    /** The tenant key of the instance tenant (`ApiStudioTenant::key()` is `''`, which a search tenant cannot be). */
    public const INSTANCE_TENANT_KEY = 'instance';

    public function __construct(
        private ?RequestStack $requestStack = null,
        private ?ApiStudioTenantContext $tenantContext = null,
        private ?ApiStudioTenantResolverInterface $tenantResolver = null,
        private ?ScopeContext $scopeContext = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function request(): ?Request
    {
        return $this->requestStack?->getMainRequest();
    }

    /**
     * The context of $kind for this viewer and search scope id (client input), or null when nothing may be searched.
     */
    public function resolve(SearchKind $kind, string $userIdentifier, ?string $searchScope): ?ApiStudioSearchContextEvent
    {
        $request = $this->request();
        if (!$request instanceof Request) {
            return null;
        }

        $key  = $kind->value . "\0" . $userIdentifier . "\0" . ($searchScope ?? "\0");
        $memo = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $memo = is_array($memo) ? $memo : [];
        if (isset($memo[$key]) && $memo[$key] instanceof ApiStudioSearchContextEvent) {
            return $memo[$key]->isDenied() ? null : $memo[$key];
        }

        $event      = $this->dispatch($kind, $request, $searchScope);
        $memo[$key] = $event;
        // @igor-ignore - Memoised on the request itself (request-scoped, not service state).
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $memo);

        return $event->isDenied() ? null : $event;
    }

    /**
     * Keeps the scope labels of a search (prefetched with the visible rows) for its rows, on the request.
     *
     * @param array<string, string> $labels scope value => label
     */
    public function rememberLabels(SearchKind $kind, string $userIdentifier, ?string $searchScope, array $labels): void
    {
        // @igor-ignore - Kept on the request itself (request-scoped, not service state).
        $this->request()?->attributes->set(self::LABELS_ATTRIBUTE . "\0" . $kind->value . "\0" . $userIdentifier . "\0" . ($searchScope ?? "\0"), $labels);
    }

    /**
     * The labels kept by {@see self::rememberLabels()} ([] when none).
     *
     * @return array<string, string>
     */
    public function rememberedLabels(SearchKind $kind, string $userIdentifier, ?string $searchScope): array
    {
        $labels = $this->request()?->attributes->get(self::LABELS_ATTRIBUTE . "\0" . $kind->value . "\0" . $userIdentifier . "\0" . ($searchScope ?? "\0"));
        if (!is_array($labels)) {
            return [];
        }

        $kept = [];
        foreach ($labels as $value => $label) {
            if (is_string($value) && is_string($label)) {
                $kept[$value] = $label;
            }
        }

        return $kept;
    }

    /**
     * Whether scopes are on (`scope.enabled`).
     */
    public function scopes(): bool
    {
        return $this->scopeContext?->isEnabled() === true;
    }

    /**
     * Runs $callback in the context's tenant and scope.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(ApiStudioSearchContextEvent $context, callable $callback): mixed
    {
        $tenant = $context->tenant() ?? ApiStudioTenant::instance();
        $scope  = $this->scopes() ? $context->workspaceScope() : null;
        $inner  = fn (): mixed => $this->scopeContext instanceof ScopeContext ? $this->scopeContext->runAs($scope, $callback) : $callback();

        return $this->tenantContext instanceof ApiStudioTenantContext ? $this->tenantContext->runAs($tenant, $inner) : $inner();
    }

    /**
     * The search tenant id of a tenant: `type:id`, or {@see self::INSTANCE_TENANT_KEY}.
     */
    public static function tenantKey(ApiStudioTenant $tenant): string
    {
        return $tenant->isInstance() ? self::INSTANCE_TENANT_KEY : $tenant->key();
    }

    /**
     * The tenant of a search tenant id (null: not one).
     */
    public static function tenantOf(int|string $key): ?ApiStudioTenant
    {
        if ($key === self::INSTANCE_TENANT_KEY) {
            return ApiStudioTenant::instance();
        }
        if (!is_string($key) || !str_contains($key, ':')) {
            return null;
        }
        [$type, $id] = explode(':', $key, 2);

        return preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $type) === 1 && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id) === 1 ? new ApiStudioTenant($type, $id) : null;
    }

    /**
     * The bundle's defaults, then the host's listeners.
     */
    private function dispatch(SearchKind $kind, Request $request, ?string $searchScope): ApiStudioSearchContextEvent
    {
        $tenant = ApiStudioTenant::instance();
        if ($this->tenantContext?->isEnabled() === true) {
            $tenant = $this->tenantResolver?->resolve($request);
        }

        $scope  = null;
        $denied = false;
        if ($searchScope !== null) {
            $valid  = $this->scopes() && preg_match(WorkspaceScope::PATTERN, $searchScope) === 1;
            $scope  = $valid ? new WorkspaceScope($searchScope) : null;
            $denied = !$valid;
        }

        $event = new ApiStudioSearchContextEvent($kind, $searchScope, $tenant, $scope, $denied);
        $this->dispatcher?->dispatch($event);

        return $event;
    }
}
