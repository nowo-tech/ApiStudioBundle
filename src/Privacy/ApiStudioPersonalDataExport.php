<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use function array_values;

/**
 * Metadata-only export of one actor (1.24, {@see ApiStudioPersonalDataExporterInterface}). Dates are ATOM strings.
 */
final readonly class ApiStudioPersonalDataExport
{
    /**
     * @param list<array{id: int, endpoint_id: int, method: string, url: string, status: int|null, executed_at: string}> $history
     * @param list<array{id: int, name: string, service_id: int, created_at: string}> $folders
     */
    public function __construct(
        public array $history = [],
        public array $folders = [],
    ) {
    }

    /**
     * This export plus $other, each row once (rows are keyed by id: two actor ids of one person never repeat a row).
     */
    public function with(self $other): self
    {
        $history = [];
        foreach ([...$this->history, ...$other->history] as $row) {
            $history[$row['id']] = $row;
        }
        $folders = [];
        foreach ([...$this->folders, ...$other->folders] as $row) {
            $folders[$row['id']] = $row;
        }

        return new self(array_values($history), array_values($folders));
    }

    /**
     * @return array{history: list<array{id: int, endpoint_id: int, method: string, url: string, status: int|null, executed_at: string}>, folders_created: list<array{id: int, name: string, service_id: int, created_at: string}>}
     */
    public function toArray(): array
    {
        return ['history' => $this->history, 'folders_created' => $this->folders];
    }
}
