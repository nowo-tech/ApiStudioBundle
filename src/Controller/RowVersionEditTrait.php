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
 * - {@see answerStaleEdit()}: on {@see StaleEditException}, drops the refused changes from the manager (refresh of the
 *   rows the form wrote) and returns the conflict answer of {@see ConcurrencyConflictSubscriber} (409 or 503,
 *   `X-Conflict-Code: CONFLICT_001` + `Retry-After`, or `CONFLICT_002` when the request committed earlier work); the page
 *   then shows the form again with the stored data and the house conflict message (error flash
 *   `conflict.stale_edit`).
 */
trait RowVersionEditTrait
{
    /**
     * Claims the row and flushes in one transaction: true when saved, false when a slug unique index refused it (form
     * error on `slug`, rolled back).
     *
     * @param FormInterface<mixed> $form
     *
     * @throws StaleEditException when the row changed (or was deleted) after the form was rendered; nothing written
     */
    private function saveEdit(FormInterface $form, RowVersionedInterface $row): bool
    {
        $expected   = $this->submittedRowVersion($form, $row);
        $connection = $this->entityManager->getConnection();
        $level      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            RowVersionGuard::claim($this->entityManager, $row, $expected);
            if ($this->flushUnlessSlugTaken($form)) {
                $connection->commit();

                return true;
            }
            $connection->rollBack();
        } catch (Throwable $e) {
            // Only the level this save opened: a caller's transaction stays the caller's to end.
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }

            throw $e;
        }

        // Rolled back: the row keeps its version, the form keeps the submitted one.
        $row->markRowVersion($expected);

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

        return is_string($value) && preg_match('/^[0-9]{1,9}$/', $value) === 1 ? (int) $value : -1;
    }

    /**
     * After a stale edit: re-reads the rows the refused form changed (the main row first, then the managed rows it
     * wrote, as they were before the form was submitted:
     * translations, variables, other environments) so nothing refused stays pending in the manager, and
     * returns the conflict status and headers. A row deleted meanwhile answers 404.
     *
     * @return array{int, array<string, string>}
     */
    private function answerStaleEdit(Request $request, StaleEditException $stale, RowVersionedInterface $row, object ...$written): array
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

        return $conflicts->answerFor($request, $stale);
    }
}
