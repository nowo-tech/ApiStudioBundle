<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Integration\UserKit;

use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * UserKit personal-data eraser `api_studio` (1.24; registered with UserKit installed and
 * `nowo_api_studio.personal_data.enabled`, the default). Calls {@see ApiStudioPersonalDataEraserInterface::erase()}
 * for every actor id of the user ({@see ApiStudioHistoryScope::actorIdsOf()} with `ErasureRequest::identifier()`, the
 * identifier before any eraser anonymised it): the request history rows they executed are deleted (bodies included),
 * the folders they created lose their creator. Priority {@see self::PRIORITY}: before host identity erasers. Inside
 * UserKit's transaction the batches are savepoints of it (no flush, no event); a lock conflict propagates so UserKit
 * rolls the whole erasure back. Matching export: {@see ApiStudioUserKitExportProvider}.
 *
 * @internal
 */
final readonly class ApiStudioUserKitEraser implements PersonalDataEraserInterface
{
    public const KEY = 'api_studio';

    public const PRIORITY = 150;

    public function __construct(
        private ApiStudioPersonalDataEraserInterface $eraser,
        private ApiStudioHistoryScope $actors,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Every user: one who never used Api Studio simply has nothing to erase.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    public function erase(ErasureRequest $request): void
    {
        foreach ($this->actors->actorIdsOf($request->user, $request->identifier()) as $actorId) {
            $this->eraser->erase($actorId);
        }
    }
}
