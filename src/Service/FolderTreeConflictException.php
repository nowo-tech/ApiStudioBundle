<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use RuntimeException;
use Throwable;

use function stripos;

/**
 * A tree write lost a race with another writer (1.14, audit RJ-D2-L1): retryable, answered 409 with Retry-After.
 *
 * On MySQL / MariaDB a deadlock inside a flush makes InnoDB roll the whole transaction back; the ORM's savepoint
 * rollback then fails with error 1305 ("SAVEPOINT … does not exist") and that error replaces the deadlock. Both are
 * reported as this exception.
 */
final class FolderTreeConflictException extends RuntimeException implements RetryableException
{
    /** MySQL / MariaDB: SAVEPOINT does not exist (the deadlock already rolled the transaction back). */
    private const SAVEPOINT_GONE = 1305;

    /**
     * $e as a retryable conflict when it (or an exception it wraps) is retryable or a lost savepoint; otherwise $e.
     */
    public static function wrap(Throwable $e): Throwable
    {
        for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return $cause === $e ? $e : new self('The folder tree changed meanwhile.', 0, $e);
            }
            // RJ-D3-I2: the code alone is not enough (1305 is also "FUNCTION … does not exist"): the message names the savepoint.
            if ($cause instanceof DriverException && $cause->getCode() === self::SAVEPOINT_GONE && stripos($cause->getMessage(), 'SAVEPOINT') !== false) {
                return new self('The folder tree changed meanwhile.', 0, $e);
            }
        }

        return $e;
    }
}
