<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Concurrency;

use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * An edit form saved on a row version that is no longer current (1.20, REQ-RUNTIME-003 rule 4): another request saved
 * the workspace, service, endpoint or environment (or deleted it) after the form was rendered. Nothing was written.
 *
 * A lock conflict for transaction-doctrine ({@see LockConflictExceptionInterface}): the edit pages answer it themselves
 * (the form again with the stored data, the conflict status and `X-Conflict-Code`); anywhere else the conflict
 * subscriber answers 409 + `CONFLICT_001` (or `CONFLICT_002` when the request committed earlier work). Client-safe
 * constant message.
 */
final class StaleEditException extends RuntimeException implements LockConflictExceptionInterface
{
    /** Translation key of the message the edit pages show (domain NowoApiStudioBundle). */
    public const MESSAGE_KEY = 'conflict.stale_edit';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The row changed after the form was rendered.', 0, $previous);
    }
}
