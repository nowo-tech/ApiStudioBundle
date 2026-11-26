<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;

/**
 * GDPR erasure port (1.24, art. 17): removes what Api Studio stores about one actor (the opaque id of
 * {@see ApiStudioHistoryScope::actorId()}, e.g. `u:<uuid>`).
 *
 * - Request history rows the actor executed (`executed_by`, request and response bodies and headers included) are
 *   deleted, in keyset batches.
 * - Folders the actor created are kept and anonymised: `created_by` becomes null (and their row version moves).
 *
 * The actor id compares byte-exact (`usera` never erases `UserA`'s rows). Every batch is one short write unit;
 * inside a caller's open transaction (UserKit's erasure) the statements join it and nothing is committed here. A lock
 * conflict propagates (the caller's unit rolls back and may be retried; erasing twice is harmless). Plain PHP
 * signature: usable from a DDD host without UserKit.
 */
interface ApiStudioPersonalDataEraserInterface
{
    /**
     * @throws InvalidArgumentException when the actor id is not 1 to 128 printable ASCII characters without spaces
     */
    public function erase(string $actorId): ApiStudioErasureResult;
}
