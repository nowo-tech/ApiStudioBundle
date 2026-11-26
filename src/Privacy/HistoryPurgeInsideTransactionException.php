<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use LogicException;

/**
 * The history purge was called inside an open transaction on the Api Studio connection (1.24): its batches must commit
 * one by one, so it refuses to start. Typical cause: a Messenger bus with the `doctrine_transaction` middleware.
 */
final class HistoryPurgeInsideTransactionException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Api Studio: the history purge commits batch by batch; run it outside a transaction (for Messenger: a bus without the doctrine_transaction middleware).');
    }
}
