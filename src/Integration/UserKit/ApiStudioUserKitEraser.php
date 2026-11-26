<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Integration\UserKit;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * UserKit personal-data eraser `api_studio` (1.24; registered with UserKit installed and
 * `nowo_api_studio.personal_data.enabled`, the default). Calls {@see ApiStudioPersonalDataEraserInterface::erase()}
 * for every actor id of the user ({@see ApiStudioHistoryScope::actorIdsOf()} with `ErasureRequest::identifier()`, the
 * identifier before any eraser anonymised it): the request history rows they executed are deleted (bodies included),
 * the folders they created lose their creator. Priority {@see self::PRIORITY}: before host identity erasers. When the
 * Api Studio manager shares UserKit's connection, the batches are savepoints of UserKit's transaction (no flush, no
 * event) and a lock conflict propagates so UserKit rolls the whole erasure back. On another connection (a dedicated
 * `entity_manager` on its own connection) each page commits on its own before UserKit commits: a later failure of
 * UserKit leaves these rows erased (idempotent: the retry completes the erasure). With a host `actor_resolver`, the
 * resolver must read an immutable key (the user id): a host eraser with a priority above 150 that anonymises the field
 * it reads makes this eraser miss the rows. Matching export: {@see ApiStudioUserKitExportProvider}.
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
        private ?LoggerInterface $logger = null,
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
        foreach ($this->actorIds($request->user, $request->identifier()) as $actorId) {
            try {
                $this->eraser->erase($actorId);
            } catch (InvalidArgumentException $e) {
                // An id the port cannot look up (audit ZCL-v-L1): no row can hold it; never abort UserKit's erasure.
                $this->logger?->warning('Api Studio: an actor id the erasure port refuses was skipped.', ['exception' => $e::class]);
            }
        }
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
            $this->logger?->warning('Api Studio: the actor resolver returned an id the bundle cannot store; nothing to erase under it.', ['exception' => $e::class]);

            return [];
        }
    }
}
