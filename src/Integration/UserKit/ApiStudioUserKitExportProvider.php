<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Integration\UserKit;

use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExport;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExporterInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * UserKit personal-data export section `api_studio` (1.24; registered like {@see ApiStudioUserKitEraser}):
 * `{history: [...], folders_created: [...]}` from {@see ApiStudioPersonalDataExporterInterface::export()} for every
 * actor id of the user. Metadata only (owner decision 2026-11-26): method, URL with secrets masked, status, times;
 * never request / response bodies, headers or error messages.
 *
 * @internal
 */
final readonly class ApiStudioUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'api_studio';

    /** After host sections, before UserKit's built-ins (-100 and below). */
    public const PRIORITY = -50;

    public function __construct(
        private ApiStudioPersonalDataExporterInterface $exporter,
        private ApiStudioHistoryScope $actors,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * @return array{history: list<array{id: int, endpoint_id: int, method: string, url: string, status: int|null, executed_at: string}>, folders_created: list<array{id: int, name: string, service_id: int, created_at: string}>}
     */
    public function export(object $user): array
    {
        $export = new ApiStudioPersonalDataExport();
        foreach ($this->actors->actorIdsOf($user) as $actorId) {
            $export = $export->with($this->exporter->export($actorId));
        }

        return $export->toArray();
    }
}
