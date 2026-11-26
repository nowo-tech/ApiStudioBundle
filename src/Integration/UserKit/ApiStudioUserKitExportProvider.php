<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Integration\UserKit;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExport;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExporterInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

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
        private ?LoggerInterface $logger = null,
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
        foreach ($this->actorIds($user, null) as $actorId) {
            try {
                $export = $export->with($this->exporter->export($actorId));
            } catch (InvalidArgumentException $e) {
                // An id the port cannot look up (audit ZCL-v-L1): no row can hold it; never abort UserKit's export.
                $this->logger?->warning('Api Studio: an actor id the export port refuses was skipped.', ['exception' => $e::class]);
            }
        }

        return $export->toArray();
    }

    /**
     * The person's actor ids. A host resolver id of the wrong shape was refused at write time too, so no row can be
     * stored under it: logged (class only) and skipped instead of aborting the whole UserKit run (audit ZCL-H1).
     *
     * @return list<string>
     */
    private function actorIds(object $user, ?string $originalIdentifier): array
    {
        try {
            return $this->actors->actorIdsOf($user, $originalIdentifier);
        } catch (UnexpectedValueException $e) {
            $this->logger?->warning('Api Studio: the actor resolver returned an id the bundle cannot store; nothing to export under it.', ['exception' => $e::class]);

            return [];
        }
    }
}
