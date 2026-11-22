<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

use InvalidArgumentException;
use LogicException;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;

use function array_keys;
use function count;
use function is_int;
use function max;
use function mb_substr;
use function min;
use function sprintf;
use function trim;

/**
 * 1.19 (DDD-host checklist B7): what {@see ApiWorkspaceQueryInterface} lists or counts. Immutable: every `with*()`
 * returns a copy. The tenant is mandatory; everything else narrows.
 *
 * - Tenant and scope compare **exactly** (bound parameters, then `===` in PHP): `('org', 'ACME')` never matches
 *   `('org', 'acme')`, nor `project:1` `Project:1`.
 * - `ids`: only these workspaces (your own permission port's answer). `[]` matches nothing. At most
 *   {@see self::MAX_IDS} ids.
 * - `text`: part of the name or the slug, case-insensitive (trimmed, cut to {@see self::MAX_TEXT} characters; `''` is
 *   no filter). A plain substring: `%`, `_` and `!` are matched literally.
 * - Paging: `page` from 1, `limit` clamped to 1–{@see self::MAX_LIMIT}.
 */
final readonly class ApiWorkspaceCriteria
{
    public const MAX_LIMIT = 100;

    /** Same bound as `ApiStudioAuthorization::MAX_VISIBLE_IDS`. */
    public const MAX_IDS = 10_000;

    public const MAX_TEXT = 128;

    /** Highest page number accepted (larger numbers are clamped: the page is then empty). */
    public const MAX_PAGE = 1_000_000;

    /**
     * @param string|null $scopeKey null = every scope, `''` = tenant level only, else that scope exactly
     * @param list<int>|null $ids
     */
    private function __construct(
        public ApiStudioTenant $tenant,
        public ?string $scopeKey = null,
        public ?array $ids = null,
        public ?string $text = null,
        public ?bool $enabled = null,
        public ApiWorkspaceSort $sort = ApiWorkspaceSort::Name,
        public bool $descending = false,
        public int $page = 1,
        public int $limit = 20,
    ) {
    }

    /**
     * Every workspace of $tenant, every scope, sorted by name, first page of 20.
     */
    public static function forTenant(ApiStudioTenant $tenant): self
    {
        return new self($tenant);
    }

    /**
     * Only the workspaces of $scope (null: only the tenant-level workspaces). With `scope.enabled` off, a scope matches
     * nothing (every workspace is at tenant level).
     */
    public function inScope(?WorkspaceScope $scope): self
    {
        return $this->copy(scopeKey: WorkspaceScope::key($scope));
    }

    /**
     * Back to every scope of the tenant (the default).
     */
    public function inAnyScope(): self
    {
        return $this->copy(anyScope: true);
    }

    /**
     * Only these workspace ids (duplicates dropped). `[]` matches nothing.
     *
     * @param array<array-key, mixed> $ids positive integers
     *
     * @throws InvalidArgumentException for an id that is not a positive integer
     * @throws LogicException for more than {@see self::MAX_IDS} ids
     */
    public function withIds(array $ids): self
    {
        $unique = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Api Studio workspace ids must be positive integers.');
            }
            $unique[$id] = true;
        }
        if (count($unique) > self::MAX_IDS) {
            throw new LogicException(sprintf('Api Studio: at most %d workspace ids per query.', self::MAX_IDS));
        }

        return $this->copy(ids: array_keys($unique));
    }

    /**
     * Part of the name or the slug (null or blank: no text filter).
     */
    public function matching(?string $text): self
    {
        $text = trim((string) $text);

        return $this->copy(text: mb_substr($text, 0, self::MAX_TEXT), noText: $text === '');
    }

    /**
     * Only enabled (true) or disabled (false) workspaces; null: both.
     */
    public function withEnabled(?bool $enabled): self
    {
        return $this->copy(enabled: $enabled, anyEnabled: $enabled === null);
    }

    public function sortedBy(ApiWorkspaceSort $sort, bool $descending = false): self
    {
        return $this->copy(sort: $sort, descending: $descending);
    }

    /**
     * Page $page (from 1) of $limit rows (clamped to 1–{@see self::MAX_LIMIT}).
     */
    public function onPage(int $page, int $limit = 20): self
    {
        return $this->copy(page: min(max(1, $page), self::MAX_PAGE), limit: min(max(1, $limit), self::MAX_LIMIT));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * @param list<int>|null $ids
     */
    private function copy(
        ?string $scopeKey = null,
        bool $anyScope = false,
        ?array $ids = null,
        ?string $text = null,
        bool $noText = false,
        ?bool $enabled = null,
        bool $anyEnabled = false,
        ?ApiWorkspaceSort $sort = null,
        ?bool $descending = null,
        ?int $page = null,
        ?int $limit = null,
    ): self {
        return new self(
            $this->tenant,
            $anyScope ? null : ($scopeKey ?? $this->scopeKey),
            $ids ?? $this->ids,
            $noText ? null : ($text ?? $this->text),
            $anyEnabled ? null : ($enabled ?? $this->enabled),
            $sort ?? $this->sort,
            $descending ?? $this->descending,
            $page ?? $this->page,
            $limit ?? $this->limit,
        );
    }
}
