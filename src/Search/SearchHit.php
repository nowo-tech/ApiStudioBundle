<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Search;

use DateTimeImmutable;

/**
 * One result of the global search (1.18), as plain text: names, the method and the path without its query string,
 * the workspace's scope label. Never a URL template, header, auth value, body, script or variable. The search box
 * escapes the text when it shows it.
 */
final readonly class SearchHit
{
    public function __construct(
        public SearchKind $kind,
        public int $id,
        public string $title,
        public string $subtitle,
        public string $url,
        /** UX icon name of the row. */
        public string $icon,
        /** Label of the workspace's host scope (tenant-level searches; null: unscoped workspace or scopes off). */
        public ?string $scopeLabel = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
