<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Concurrency;

use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use RuntimeException;
use Throwable;

/**
 * An edit form saved on a row version that is no longer current (1.20, REQ-RUNTIME-003 rule 4): another request saved
 * the workspace, service, endpoint or environment (or deleted it) after the form was rendered. Nothing was written.
 *
 * A **domain** conflict, not a lock conflict (1.21, house convention): no deadlock, lock wait timeout or serialization
 * failure happened, nothing was written, and repeating the same request can never succeed — the client has to read the
 * stored row again and decide. It is answered with {@see STATUS} and `X-Error-Code: API_STUDIO_STALE_EDIT`
 * ({@see HEADER} / {@see ERROR_CODE}), never with the transaction contract's `X-Conflict-Code: CONFLICT_00x` (which
 * invites a retry) and never with `Retry-After`. The edit pages answer it themselves (the form again with the stored
 * data); anywhere else {@see ConcurrencyConflictSubscriber} answers it.
 *
 * Deliberately **not** a transaction-doctrine `LockConflictExceptionInterface` (it was until 1.20): that marker makes
 * every conflict responder answer `CONFLICT_001` + `Retry-After`. Client-safe constant message.
 */
final class StaleEditException extends RuntimeException
{
    /** Translation key of the message the edit pages show (domain NowoApiStudioBundle). */
    public const MESSAGE_KEY = 'conflict.stale_edit';

    /** Header carrying the domain error code of the answer (never `X-Conflict-Code`). */
    public const HEADER = 'X-Error-Code';

    /** Value of {@see HEADER}: the house `<THING>_STALE_EDIT` code of this bundle. */
    public const ERROR_CODE = 'API_STUDIO_STALE_EDIT';

    /** Always 409: a domain conflict is not the configurable lock-conflict status (`concurrency.conflict_status`). */
    public const STATUS = 409;

    /** `error` of the JSON answer of a console route (the bundle's domain error shape: `error` plus `code`). */
    public const ERROR = 'stale_edit';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The row changed after the form was rendered.', 0, $previous);
    }
}
