<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;

/**
 * One batch of a personal-data or retention write (1.24) as one write unit through the transaction-doctrine runner
 * (REQ-RUNTIME-003 rules 1 and 3: its own short transaction, or a savepoint of the caller's, repaired after any
 * failure). A lock conflict ({@see TransientStorageFailure}) is retried while the connection was outside a
 * transaction (the batch is idempotent: it writes by primary key); inside a caller's transaction it propagates at
 * once, since the server may already have rolled the caller's work back.
 *
 * @internal
 */
final readonly class BatchWriter
{
    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null,
        private int $maxAttempts = 3,
    ) {
    }

    /**
     * Runs $sql (one `?` placeholder: the id) once per id and returns the rows it changed. One statement per row keeps
     * every lock a primary-key point lock: an `IN (…)` list may be run as a scan that locks each row it reads.
     *
     * @param list<int> $ids
     */
    public static function eachId(Connection $connection, string $sql, array $ids): int
    {
        $rows = 0;
        foreach ($ids as $id) {
            $rows += (int) $connection->executeStatement($sql, [$id], [ParameterType::INTEGER]);
        }

        return $rows;
    }

    /**
     * @param callable(): int $statement
     *
     * @return int rows written
     */
    public function write(callable $statement): int
    {
        $nested = $this->connection->isTransactionActive();
        for ($attempt = 1;; ++$attempt) {
            try {
                /** @var int $rows */
                $rows = DoctrineTransactionRunner::forConnection($this->connection, 1, $this->logger)->run($statement);

                return $rows;
            } catch (TransientStorageFailure $failure) {
                if ($nested || $attempt >= $this->maxAttempts) {
                    throw $failure;
                }
                $this->logger?->notice('Api Studio: batch write conflict, retrying.', ['attempt' => $attempt, 'exception' => $failure::class]);
            }
        }
    }
}
