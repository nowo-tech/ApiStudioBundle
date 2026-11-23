<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function is_string;
use function preg_match;

/**
 * Edit forms guarded by the row version (1.20, REQ-RUNTIME-003 rule 4) for the workspace, service, endpoint and
 * environment pages. Needs the controller's `$entityManager`, {@see SlugConflictTrait} and the
 * {@see ApiStudioPageTrait} services.
 *
 * - {@see saveEdit()}: one transaction — claim the row at the version the form was rendered from, then flush.
 * - {@see saveNew()} (1.21.1): one transaction — the parent locks of a create page, then flush.
 * - {@see answerStaleEdit()}: on {@see StaleEditException}, drops the refused changes from the manager (refresh of the
 *   rows the form wrote) and returns the domain stale-edit answer of {@see ConcurrencyConflictSubscriber} (1.21: 409 +
 *   `X-Error-Code: API_STUDIO_STALE_EDIT`, no `X-Conflict-Code` and no `Retry-After` — nothing was written and the same
 *   save can only be refused again); the page then shows the form again with the stored data and the house conflict
 *   message (error flash `conflict.stale_edit`).
 */
trait RowVersionEditTrait
{
    /**
     * Claims the row and flushes in one transaction: true when saved, false when a slug unique index refused it (form
     * error on `slug`, rolled back).
     *
     * @param FormInterface<mixed> $form
     * @param (callable(): void)|null $beforeClaim Runs first inside the transaction (parent locks)
     *
     * @throws StaleEditException when the row changed (or was deleted) after the form was rendered; nothing written
     */
    private function saveEdit(FormInterface $form, RowVersionedInterface $row, ?callable $beforeClaim = null): bool
    {
        $expected = $this->submittedRowVersion($form, $row);
        $saved    = $this->flushInTransaction($form, function () use ($row, $expected, $beforeClaim): void {
            // Locks a parent row first when the save writes more than its own row (the default environment flag).
            if ($beforeClaim !== null) {
                $beforeClaim();
            }
            RowVersionGuard::claim($this->entityManager, $row, $expected);
        });

        if (!$saved) {
            // Rolled back: the row keeps its version, the form keeps the submitted one.
            $row->markRowVersion($expected);
        }

        return $saved;
    }

    /**
     * A create page's save (1.21.1): the parent locks of `$beforeFlush`, then the flush, in one transaction with the
     * same failure repair as {@see saveEdit()}. True when saved, false when a slug unique index refused it (form error
     * on `slug`, rolled back). A lock conflict propagates to the conflict subscriber (409 + `CONFLICT_00x`).
     *
     * @param FormInterface<mixed> $form
     * @param callable(): void $beforeFlush Runs first inside the transaction (parent locks)
     */
    private function saveNew(FormInterface $form, callable $beforeFlush): bool
    {
        return $this->flushInTransaction($form, $beforeFlush);
    }

    /**
     * @param FormInterface<mixed> $form
     * @param callable(): void $before
     */
    private function flushInTransaction(FormInterface $form, callable $before): bool
    {
        $connection = $this->entityManager->getConnection();
        $level      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $before();
            if ($this->flushUnlessSlugTaken($form)) {
                $connection->commit();

                return true;
            }
            $connection->rollBack();
        } catch (Throwable $e) {
            // Audit ZH-M1: repair the nesting level, savepoint-aware, then rethrow the original failure.
            try {
                // Only the levels this save opened: a caller's transaction stays the caller's to end.
                while ($connection->getTransactionNestingLevel() > $level) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // MySQL / MariaDB after a deadlock in the flush: the server rolled the whole transaction back and the
                // flush's savepoint is gone (1305), so the level cannot be unwound. Closing resets it to 0 (the next
                // statement reconnects); a caller's transaction was lost with the server rollback anyway.
                $connection->close();
            }

            // The original failure keeps the deadlock in its chain: the conflict subscriber answers 409 + CONFLICT_001.
            throw $e;
        }

        return false;
    }

    /**
     * The version the form was rendered from. A form posted without the field's value (a template override
     * that drops hidden fields) falls back to the version loaded by this request; a malformed value never matches.
     *
     * @param FormInterface<mixed> $form
     */
    private function submittedRowVersion(FormInterface $form, RowVersionedInterface $row): int
    {
        $value = $form->get(RowVersionField::NAME)->getData();
        if ($value === null || $value === '') {
            return $row->getRowVersion();
        }

        // Audit ZH-I4: any stored INT value (up to 2147483647); anything else never matches.
        return is_string($value) && preg_match('/^[0-9]{1,10}$/', $value) === 1 && (int) $value <= 2147483647 ? (int) $value : -1;
    }

    /**
     * After a stale edit: re-reads the rows the refused form changed (the main row first, then the managed rows it
     * wrote, as they were before the form was submitted:
     * translations, variables, other environments) so nothing refused stays pending in the manager, and
     * returns the stale-edit status and headers (409 + `X-Error-Code`). A row deleted meanwhile answers 404.
     *
     * @return array{int, array<string, string>}
     */
    private function answerStaleEdit(Request $request, RowVersionedInterface $row, object ...$written): array
    {
        $uow = $this->entityManager->getUnitOfWork();
        foreach ($written as $entity) {
            // Rows added by the form were never persisted: the main row's refresh drops them from its collection.
            if ($this->entityManager->contains($entity)) {
                // A row the form took out of a collection (orphan removal is scheduled at once): keep it.
                $uow->cancelOrphanRemoval($entity);
                $this->entityManager->refresh($entity);
            }
        }
        if (RowVersionGuard::storedVersion($this->entityManager, $row) === null) {
            throw $this->createNotFoundException('Not found.'); // Deleted by the other writer.
        }
        $this->entityManager->refresh($row);

        /** @var ConcurrencyConflictSubscriber $conflicts */
        $conflicts = $this->container->get(ConcurrencyConflictSubscriber::class);

        return $conflicts->answerForStaleEdit($request);
    }
}
